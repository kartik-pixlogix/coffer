<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Permissions\Catalog;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Who hears about an event (spec §19, D78): the company's people whose role, under the
 * company's permission matrix, holds a page or action — Admins always — or a workspace's
 * owner and Admins for billing. Works for anyone, not just the signed-in user.
 */
final class Audience
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @param  string  $permission  e.g. Catalog::actionPermission('expenses.approve')
     * @return Collection<int, User>
     */
    public function withPermission(Company $company, string $permission, ?User $except = null): Collection
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($company->id);

        try {
            return Membership::query()
                ->where('company_id', $company->id)
                ->with('user')
                ->get()
                ->filter(fn (Membership $m) => $m->user_id !== $except?->id && $this->holds($m, $permission))
                ->map(fn (Membership $m) => $m->user)
                ->values();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }

    /** @return Collection<int, User> */
    public function admins(Company $company, ?User $except = null): Collection
    {
        return Membership::query()
            ->where('company_id', $company->id)
            ->where('role', CompanyRole::Admin->value)
            ->with('user')
            ->get()
            ->map(fn (Membership $m) => $m->user)
            ->reject(fn (User $u) => $u->id === $except?->id)
            ->values();
    }

    /**
     * Billing's people (spec §2.1 "workspace owner/admin"): the owner and every Admin of
     * the workspace's companies, once each.
     *
     * @return Collection<int, User>
     */
    public function billing(Workspace $workspace): Collection
    {
        $admins = User::query()
            ->whereIn('id', Membership::query()
                ->whereIn('company_id', $workspace->companies()->select('id'))
                ->where('role', CompanyRole::Admin->value)
                ->select('user_id'))
            ->get();

        return $admins->push($workspace->owner)->unique('id')->values();
    }

    private function holds(Membership $membership, string $permission): bool
    {
        if ($membership->role === CompanyRole::Admin) {
            return true;
        }

        if (Catalog::isLocked($permission)) {
            return false;
        }

        try {
            return $membership->user->unsetRelation('roles')->unsetRelation('permissions')->checkPermissionTo($permission);
        } catch (Throwable) {
            return false;
        } finally {
            $membership->user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
