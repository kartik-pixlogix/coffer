<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Enums\ExpenseStatus;
use App\Enums\PayerMode;
use App\Enums\PayerType;
use App\Ledger\ChartOfAccounts;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpensePayer;
use App\Models\Member;
use App\Models\SalaryRun;

/**
 * Keeps a salary run's "Auto" expense row in step with it (D38, D43): approved, category
 * Salary, paid from the member's partner's account, pointing at the run's journal entry.
 * The run stays the source of that entry; the row only shows it in Expenses, read-only.
 */
final class SalaryExpense
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    public function sync(Company $company, Member $member, SalaryRun $run): void
    {
        $existing = Expense::query()->withoutGlobalScope('company')->withTrashed()->where('salary_run_id', $run->id)->first();

        if ($run->journal_entry_id === null || $run->amount_paise === 0) {
            $existing?->forceDelete();

            return;
        }

        $partner = $member->partner()->withoutGlobalScopes()->firstOrFail();
        $from = $this->chart->payingAccountFor($partner);
        $monthEnd = $run->month->endOfMonth()->startOfDay();

        $expense = $existing ?? new Expense(['company_id' => $company->id, 'salary_run_id' => $run->id]);
        $expense->fill([
            'description' => 'Salary — '.$member->name.' — '.$run->month->format('Y-m'),
            'amount_paise' => $run->amount_paise,
            'spent_on' => $monthEnd->toDateString(),
            'category_id' => $this->chart->salaryCategory($company)->id,
            'payer_mode' => PayerMode::Full,
            'status' => ExpenseStatus::Approved,
            'auto' => true,
            'submitted_by' => null,
            'decided_by' => $run->run_by,
            'decided_at' => $run->posted_at,
            'journal_entry_id' => $run->journal_entry_id,
        ]);
        $expense->deleted_at = null;
        $expense->save();

        ExpensePayer::query()->withoutGlobalScope('company')->where('expense_id', $expense->id)->delete();
        ExpensePayer::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'expense_id' => $expense->id,
            'position' => 0,
            'payer_type' => PayerType::Member,
            'member_id' => $member->id,
            'money_account_id' => $from->id,
            'amount_paise' => $run->amount_paise,
        ]);
    }
}
