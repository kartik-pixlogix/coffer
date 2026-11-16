<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Income;
use App\Models\User;

/** Income (spec §2.2 "Add / edit income"): the Income page to see it, the action to change it. */
final class IncomePolicy
{
    public function view(User $user, Income $income): bool
    {
        return $user->can('page.income');
    }

    public function create(User $user): bool
    {
        return $user->can('action.income.manage');
    }

    public function update(User $user, Income $income): bool
    {
        return $user->can('action.income.manage');
    }

    public function delete(User $user, Income $income): bool
    {
        return $user->can('action.income.manage');
    }
}
