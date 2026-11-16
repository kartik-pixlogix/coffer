<?php

declare(strict_types=1);

namespace App\Actions\Companies;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * The one place a user's role in a company is set. Writes `company_user.role` (the
 * canonical role) and mirrors it into spatie's team-scoped `model_has_roles`.
 */
final class SetCompanyRole
{
    public function __construct(
        private readonly SeedCompanyPermissions $seedPermissions,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Company $company, User $user, CompanyRole $role): Membership
    {
        /** @var Membership $membership */
        $membership = Membership::query()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            ['role' => $role],
        );

        $spatieRole = $this->seedPermissions->roleFor($company, $role);
        $previousTeam = $this->registrar->getPermissionsTeamId();

        $this->registrar->setPermissionsTeamId($company->id);

        try {
            $user->unsetRelation('roles')->syncRoles([$spatieRole]);
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $membership;
    }

    /** Remove a user from a company (membership and team role). */
    public function remove(Company $company, User $user): void
    {
        $previousTeam = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($company->id);

        try {
            $user->unsetRelation('roles')->syncRoles([]);
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeam);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        Membership::query()->where('company_id', $company->id)->where('user_id', $user->id)->delete();
    }
}
