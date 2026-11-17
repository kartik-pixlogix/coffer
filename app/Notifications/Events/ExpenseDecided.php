<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Notifications\CofferNotification;
use App\Support\Money;

/** Your expense was approved or rejected (spec §19 "to the submitter"), with the reason. */
final class ExpenseDecided extends CofferNotification
{
    public static function for(Expense $expense, string $by): self
    {
        $approved = $expense->status === ExpenseStatus::Approved;
        $what = "{$expense->description} · ".Money::format($expense->amount_paise);

        return new self(
            $expense->company_id,
            $approved ? "{$by} approved your expense" : "{$by} rejected your expense",
            $approved ? $what : "{$what} · “{$expense->reject_reason}”",
            route('expenses.index', ['expense' => $expense->id]),
            ['type' => $approved ? 'approved' : 'rejected', 'id' => $expense->id],
        );
    }

    public function event(): string
    {
        return 'expense.decided';
    }

    public function icon(): string
    {
        return 'receipt';
    }

    public function tone(): string
    {
        return $this->subject !== null && $this->subject['type'] === 'rejected' ? 'neg' : 'lime';
    }

    public function linkLabel(): string
    {
        return 'Open the expense';
    }
}
