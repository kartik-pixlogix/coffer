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

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Income => 'Income',
            self::Expense => 'Expense',
            self::Reimbursement => 'Reimbursement',
            self::Salary => 'Salary',
            self::Contribution => 'Capital contribution',
            self::Drawing => 'Drawing',
            self::Loan => 'Loan',
            self::Repayment => 'Loan repayment',
            self::LoanConversion => 'Loan conversion',
            self::Settlement => 'Settlement transfer',
            self::FyClose => 'Year-end close',
        };
    }

    /** The voucher series code (D154): INC/2026-27/0001. */
    public function voucherCode(): string
    {
        return match ($this) {
            self::Opening => 'OPN',
            self::Income => 'INC',
            self::Expense => 'EXP',
            self::Reimbursement => 'RMB',
            self::Salary => 'SAL',
            self::Contribution => 'CAP',
            self::Drawing => 'DRW',
            self::Loan => 'LON',
            self::Repayment => 'RPY',
            self::LoanConversion => 'LCV',
            self::Settlement => 'STL',
            self::FyClose => 'FYC',
        };
    }
}
