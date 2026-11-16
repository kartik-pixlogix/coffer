<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Member;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * Timesheets (spec §2.2 "Log time", §8.1): logging, editing and deleting need "Log time";
 * a user with the Member role does all three only for their own linked team member.
 */
final class TimeEntryPolicy
{
    public function __construct(private readonly CurrentCompany $current) {}

    /** Log hours for this member. */
    public function create(User $user, Member $member): bool
    {
        return $user->can('action.time.log') && $this->mayTouch($user, $member);
    }

    public function update(User $user, TimeEntry $entry): bool
    {
        return $user->can('action.time.log') && $this->mayTouch($user, $entry->member);
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    /** Members see and log only their own entries (spec §8.1). */
    public function ownOnly(): bool
    {
        return $this->current->role() === CompanyRole::Member;
    }

    private function mayTouch(User $user, Member $member): bool
    {
        return ! $this->ownOnly() || $member->user_id === $user->id;
    }
}
