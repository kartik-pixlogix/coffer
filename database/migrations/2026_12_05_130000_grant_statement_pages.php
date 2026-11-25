<?php

use App\Enums\CompanyRole;
use App\Support\Permissions\Catalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** The pages Phase 11 adds (D153–D158). */
    private const array PAGES = ['audit_trail', 'journal', 'cash_flow', 'chart_of_accounts'];

    /**
     * Existing companies get the new pages with the spec defaults for each role, without
     * touching anything an Admin already changed in their matrix. Admins see everything anyway.
     */
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (self::PAGES as $page) {
            Permission::query()->firstOrCreate(['name' => Catalog::pagePermission($page), 'guard_name' => 'web']);
        }

        $registrar->forgetCachedPermissions();

        foreach (Role::query()->with('permissions')->whereIn('name', array_map(fn (CompanyRole $r) => $r->value, Catalog::EDITABLE_ROLES))->get() as $role) {
            $grant = array_values(array_filter(self::PAGES, fn (string $page) => Catalog::PAGES[$page]['defaults'][$role->name] ?? false));

            if ($grant !== []) {
                $role->givePermissionTo(array_map(Catalog::pagePermission(...), $grant));
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', array_map(Catalog::pagePermission(...), self::PAGES))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
