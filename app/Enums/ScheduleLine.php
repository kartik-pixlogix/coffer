<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an income or expense category reports in the Schedule III statement of profit and
 * loss (Division I, D156). Each category has one; a category without a choice uses the
 * default below, and Admins change it on the Chart of accounts.
 */
enum ScheduleLine: string
{
    case RevenueFromOperations = 'revenue_from_operations';
    case OtherIncome = 'other_income';
    case EmployeeBenefits = 'employee_benefits';
    case FinanceCosts = 'finance_costs';
    case Depreciation = 'depreciation';
    case OtherExpenses = 'other_expenses';

    public function label(): string
    {
        return match ($this) {
            self::RevenueFromOperations => 'Revenue from operations',
            self::OtherIncome => 'Other income',
            self::EmployeeBenefits => 'Employee benefits expense',
            self::FinanceCosts => 'Finance costs',
            self::Depreciation => 'Depreciation and amortisation expense',
            self::OtherExpenses => 'Other expenses',
        };
    }

    public function kind(): CategoryKind
    {
        return in_array($this, [self::RevenueFromOperations, self::OtherIncome], true) ? CategoryKind::Income : CategoryKind::Expense;
    }

    /** @return list<self> the lines a category of this kind may use, in statement order */
    public static function for(CategoryKind $kind): array
    {
        return array_values(array_filter(self::cases(), fn (self $line) => $line->kind() === $kind));
    }

    /** Salary is employee benefits; the "Other" income category is other income; the rest is revenue or other expenses. */
    public static function defaultFor(CategoryKind $kind, string $name, bool $system): self
    {
        if ($kind === CategoryKind::Income) {
            return strcasecmp($name, 'Other') === 0 ? self::OtherIncome : self::RevenueFromOperations;
        }

        return $system ? self::EmployeeBenefits : self::OtherExpenses;
    }
}
