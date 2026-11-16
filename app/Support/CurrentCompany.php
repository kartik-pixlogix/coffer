<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CompanyRole;
use App\Models\Company;
use Closure;
use LogicException;

/**
 * The company the current request works in (the company switcher's choice).
 * Set by the SetCurrentCompany middleware; read by the BelongsToCompany scope,
 * spatie's team-scoped permissions and the shared Inertia props.
 */
final class CurrentCompany
{
    /** Session key holding the chosen company id. */
    public const string SESSION_KEY = 'current_company_id';

    private ?Company $company = null;

    /** The signed-in user's role in the current company. */
    private ?CompanyRole $role = null;

    public function set(?Company $company, ?CompanyRole $role = null): void
    {
        $this->company = $company;
        $this->role = $company === null ? null : $role;

        setPermissionsTeamId($company?->id);
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function role(): ?CompanyRole
    {
        return $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === CompanyRole::Admin;
    }

    public function require(): Company
    {
        return $this->company ?? throw new LogicException('No current company is set for this request.');
    }

    /**
     * Run a callback as if `$company` were current (seeders, jobs, launch), then restore.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Company $company, Closure $callback): mixed
    {
        [$previous, $previousRole] = [$this->company, $this->role];
        $this->set($company, $this->role);

        try {
            return $callback();
        } finally {
            $this->set($previous, $previousRole);
        }
    }
}
