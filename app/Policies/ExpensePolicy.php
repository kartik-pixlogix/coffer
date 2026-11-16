<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Enums\PayerType;
use App\Models\Expense;
use App\Models\ExpensePayer;
use App\Models\Member;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;

/**
 * Expenses (spec §2.2, §10):
 * - "Add expense" to submit; a user with the Member role sees and changes only what they
 *   submitted, and only while it isn't approved.
 * - Editing or deleting an approved expense is for Admins (it posts a reversal).
 * - "Approve / reject" for pending ones, never your own unless you're an Admin. Your own
 *   means you submitted it, or it reimburses you (your personal card is a payer, D44).
 * - Auto rows (salary runs) can't be touched by hand.
 */
final class ExpensePolicy
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function view(User $user, Expense $expense): bool
    {
        return $user->can('page.expenses') && (! $this->ownOnly() || $expense->submitted_by === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can('action.expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        if ($expense->auto || ! $user->can('action.expenses.create')) {
            return false;
        }

        if ($expense->isApproved()) {
            return $this->current->isAdmin();
        }

        return ! $this->ownOnly() || $expense->submitted_by === $user->id;
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->update($user, $expense);
    }

    public function decide(User $user, Expense $expense): bool
    {
        return ! $expense->auto
            && $expense->isPending()
            && $user->can('action.expenses.approve')
            && ($this->current->isAdmin() || ! $this->isOwn($user, $expense));
    }

    /** Members see and submit only their own expenses (spec §2.1 "own submissions"). */
    public function ownOnly(): bool
    {
        return $this->current->role() === CompanyRole::Member;
    }

    private function isOwn(User $user, Expense $expense): bool
    {
        if ($expense->submitted_by === $user->id) {
            return true;
        }

        $partnerIds = Partner::query()->where('user_id', $user->id)->pluck('id');
        $memberIds = Member::query()->where('user_id', $user->id)->pluck('id');

        return $expense->payers->contains(fn (ExpensePayer $p) => $p->isPersonal() && (
            ($p->payer_type === PayerType::Partner && $partnerIds->contains($p->partner_id))
            || ($p->payer_type === PayerType::Member && $memberIds->contains($p->member_id))
        ));
    }
}
