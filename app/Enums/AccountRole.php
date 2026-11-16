<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an account in the chart stands for (spec §4.1), with its type and code prefix.
 * Codes: "1000-1" (money), "1200-1" (partner current), "2000-1" (loans), "2100-1"
 * (reimbursements), "3000-1" (capital), "4001" (income), "5001" (expense).
 */
enum AccountRole: string
{
    case Money = 'money';
    case PartnerCurrent = 'partner_current';
    case LoansPayable = 'loans_payable';
    case ReimbursementsPayable = 'reimbursements_payable';
    case Capital = 'capital';
    case Income = 'income';
    case Expense = 'expense';

    public function type(): AccountType
    {
        return match ($this) {
            self::Money, self::PartnerCurrent => AccountType::Asset,
            self::LoansPayable, self::ReimbursementsPayable => AccountType::Liability,
            self::Capital => AccountType::Equity,
            self::Income => AccountType::Income,
            self::Expense => AccountType::Expense,
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Money => '1000',
            self::PartnerCurrent => '1200',
            self::LoansPayable => '2000',
            self::ReimbursementsPayable => '2100',
            self::Capital => '3000',
            self::Income => '4',
            self::Expense => '5',
        };
    }

    /** "1000-3" for dashed roles, "4003" for categories. */
    public function code(int $number): string
    {
        return in_array($this, [self::Income, self::Expense], true)
            ? $this->prefix().str_pad((string) $number, 3, '0', STR_PAD_LEFT)
            : $this->prefix().'-'.$number;
    }

    /** Which balance is "normal": debit for assets and expenses, credit otherwise. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type(), [AccountType::Asset, AccountType::Expense], true);
    }
}
