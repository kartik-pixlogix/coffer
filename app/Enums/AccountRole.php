<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an account in the chart stands for (spec §4.1), with its type and code prefix.
 * Codes: "1000-1" (money), "1200-1" (partner current), "2000-1" (loans), "2100-1"
 * (reimbursements), "3000-1" (capital), "4001" (income), "5001" (expense); and the tax
 * accounts (D161): "1300-1" GST input, "1400-1" TDS receivable, "2200-1" GST output, "2210-1"
 * RCM payable, "2300-1" TDS payable, one per head or section.
 */
enum AccountRole: string
{
    /** The tax accounts (D161). */
    public const array TAX = [self::GstInput, self::TdsReceivable, self::GstOutput, self::RcmPayable, self::TdsPayable];

    case Money = 'money';
    case PartnerCurrent = 'partner_current';
    case LoansPayable = 'loans_payable';
    case ReimbursementsPayable = 'reimbursements_payable';
    case Capital = 'capital';
    case Income = 'income';
    case Expense = 'expense';
    case GstInput = 'gst_input';
    case TdsReceivable = 'tds_receivable';
    case GstOutput = 'gst_output';
    case RcmPayable = 'rcm_payable';
    case TdsPayable = 'tds_payable';

    public function type(): AccountType
    {
        return match ($this) {
            self::Money, self::PartnerCurrent, self::GstInput, self::TdsReceivable => AccountType::Asset,
            self::LoansPayable, self::ReimbursementsPayable, self::GstOutput, self::RcmPayable, self::TdsPayable => AccountType::Liability,
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
            self::GstInput => '1300',
            self::TdsReceivable => '1400',
            self::GstOutput => '2200',
            self::RcmPayable => '2210',
            self::TdsPayable => '2300',
        };
    }

    /** "1000-3" for dashed roles, "4003" for categories. */
    public function code(int $number): string
    {
        return in_array($this, [self::Income, self::Expense], true)
            ? $this->prefix().str_pad((string) $number, 3, '0', STR_PAD_LEFT)
            : $this->prefix().'-'.$number;
    }

    /** A tax account (D161): its lines carry no side or partner, so they pool across a JV's sides. */
    public function isTax(): bool
    {
        return in_array($this, self::TAX, true);
    }

    /** Which balance is "normal": debit for assets and expenses, credit otherwise. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type(), [AccountType::Asset, AccountType::Expense], true);
    }
}
