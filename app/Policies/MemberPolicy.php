<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Member;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * Team (spec §2.1, §2.2, §7). The Team page permission opens it; a user with the Member
 * role only sees their own profile. Adding, editing and deactivating need "Manage
 * team"; changing a rate also needs "Edit rates".
 */
final class MemberPolicy
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function view(User $user, Member $member): bool
    {
        if (! $user->can('page.team')) {
            return false;
        }

        return ! $this->ownOnly() || $member->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('action.team.manage');
    }

    public function update(User $user, Member $member): bool
    {
        return $user->can('action.team.manage');
    }

    public function changeRate(User $user, ?Member $member = null): bool
    {
        return $user->can('action.team.manage') && $user->can('action.rates.edit');
    }

    public function delete(User $user, Member $member): bool
    {
        return $user->can('action.team.manage');
    }

    /** Members see and log only their own records (spec §2.1 "own" rows). */
    public function ownOnly(): bool
    {
        return $this->current->role() === CompanyRole::Member;
    }
}
