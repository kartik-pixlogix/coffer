<?php

use App\Enums\CompanyRole;
use App\Support\Permissions\Catalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** The page Phase 12 adds (D164); its "Manage tax" action is Admin-only by default, so no role gets it. */
    private const array PAGES = ['tax'];

    /**
     * Existing companies get the Tax page with the default for each role (Partners), without
     * touching anything an Admin already changed in their matrix. Admins see everything anyway.
     */
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach (self::PAGES as $page) {
            Permission::query()->firstOrCreate(['name' => Catalog::pagePermission($page), 'guard_name' => 'web']);
        }

        Permission::query()->firstOrCreate(['name' => Catalog::actionPermission('tax.manage'), 'guard_name' => 'web']);

        $registrar->forgetCachedPermissions();

        foreach (Role::query()->with('permissions')->whereIn('name', array_map(fn (CompanyRole $r) => $r->value, Catalog::EDITABLE_ROLES))->get() as $role) {
            $grant = array_filter(self::PAGES, fn (string $page) => Catalog::PAGES[$page]['defaults'][$role->name] ?? false);

            if ($grant !== []) {
                $role->givePermissionTo(array_map(Catalog::pagePermission(...), $grant));
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', [...array_map(Catalog::pagePermission(...), self::PAGES), Catalog::actionPermission('tax.manage')])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
