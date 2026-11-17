<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Expense;
use App\Notifications\CofferNotification;
use App\Support\Money;

/** An expense waits for approval (spec §19 "expense submitted (to approvers)"); approvable inline. */
final class ExpenseSubmitted extends CofferNotification
{
    public static function for(Expense $expense, string $by): self
    {
        return new self(
            $expense->company_id,
            "{$by} added an expense",
            "{$expense->description} · ".Money::format($expense->amount_paise)." · {$expense->category->name}",
            route('expenses.index', ['expense' => $expense->id]),
            ['type' => 'expense', 'id' => $expense->id],
        );
    }

    public function event(): string
    {
        return 'expense.submitted';
    }

    public function icon(): string
    {
        return 'receipt';
    }

    public function tone(): string
    {
        return 'lime';
    }

    public function linkLabel(): string
    {
        return 'Open the expense';
    }

    public function mailAction(): string
    {
        return 'Review the expense';
    }
}
