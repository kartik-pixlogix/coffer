<?php

declare(strict_types=1);

namespace App\Actions\Companies;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Support\Permissions\Catalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a company's four team-scoped roles with the spec §2 defaults, or puts an
 * existing company's matrix back to them ("Reset to defaults").
 */
final class SeedCompanyPermissions
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function handle(Company $company): void
    {
        $this->ensurePermissionsExist();

        foreach (CompanyRole::cases() as $role) {
            $this->roleFor($company, $role)->syncPermissions(Catalog::defaultsFor($role));
        }

        $this->registrar->forgetCachedPermissions();
    }

    public function roleFor(Company $company, CompanyRole $role): Role
    {
        /** @var Role */
        return Role::query()->firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            $this->registrar->teamsKey => $company->id,
        ]);
    }

    private function ensurePermissionsExist(): void
    {
        $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        foreach (array_diff(Catalog::all(), $existing) as $name) {
            Permission::query()->create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->registrar->forgetCachedPermissions();
    }
}
