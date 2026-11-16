<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a journal entry records (spec §4.2). A reversal keeps the type of the entry it
 * reverses (and points at it with `reverses_id`), so it nets out of the same figures.
 */
enum JournalType: string
{
    case Opening = 'opening';
    case Income = 'income';
    case Expense = 'expense';
    case Reimbursement = 'reimbursement';
    case Salary = 'salary';
    case Contribution = 'contribution';
    case Drawing = 'drawing';
    case Loan = 'loan';
    case Repayment = 'repayment';
    case LoanConversion = 'loan_conversion';
    case Settlement = 'settlement';
    case FyClose = 'fy_close';
}
