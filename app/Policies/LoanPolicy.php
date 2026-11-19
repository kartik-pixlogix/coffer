<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

/**
 * Partner loans (spec §2.2 "Manage capital and loans", §12): the Partner loans page to
 * see them, the action to record, edit or delete one.
 */
final class LoanPolicy
{
    public function view(User $user, Loan $loan): bool
    {
        return $user->can('page.partner_loans');
    }

    public function update(User $user, Loan $loan): bool
    {
        return $user->can('action.capital.manage');
    }

    public function delete(User $user, Loan $loan): bool
    {
        return $user->can('action.capital.manage');
    }
}
