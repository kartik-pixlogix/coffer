<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\JournalType;
use App\Models\Company;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use stdClass;

/**
 * Statements built from journal lines (spec §4.3, §15): profit and loss by month and
 * category, trial balance, balance sheet, capital statement and the dashboard figures.
 * Every amount is integer paise. Grouping by month happens in PHP so the SQL stays
 * portable between MySQL and PostgreSQL.
 */
final class Reports
{
    public function __construct(private readonly ProfitAllocator $allocator) {}

    /**
     * Income and expenses for a period (spec §4.3, D6: "other" is every non-salary expense).
     *
     * @return array{income: int, expenses: int, salary: int, other: int, net: int}
     */
    public function profitAndLoss(Company $company, Period $period): array
    {
        $totals = ['income' => 0, 'expenses' => 0, 'salary' => 0, 'other' => 0, 'net' => 0];

        foreach ($this->pnlRows($company, $period) as $row) {
            $totals = $this->addRow($totals, $row);
        }

        return $totals;
    }

    /**
     * The monthly table on Reports (spec §15).
     *
     * @return list<array{month: string, income: int, expenses: int, salary: int, other: int, net: int}>
     */
    public function profitAndLossByMonth(Company $company, Period $period): array
    {
        $months = [];

        foreach ($this->pnlRows($company, $period) as $row) {
            $month = substr((string) $row->date, 0, 7);
            $months[$month] ??= ['month' => $month, 'income' => 0, 'expenses' => 0, 'salary' => 0, 'other' => 0, 'net' => 0];
            $months[$month] = ['month' => $month, ...$this->addRow($months[$month], $row)];
        }

        ksort($months);

        return array_values($months);
    }

    /**
     * Income and expense per category for a period.
     *
     * @return list<array{category_id: int, name: string, kind: string, amount: int}>
     */
    public function byCategory(Company $company, Period $period): array
    {
        return array_values(Lines::profitAndLoss($company, $period)
            ->join('categories as c', 'c.id', '=', 'a.category_id')
            ->selectRaw('c.id as category_id, c.name, c.kind, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('c.id', 'c.name', 'c.kind')
            ->orderBy('c.kind')
            ->orderBy('c.name')
            ->get()
            ->map(fn ($r) => [
                'category_id' => (int) $r->category_id,
                'name' => (string) $r->name,
                'kind' => (string) $r->kind,
                // Shown positive: income as received, expenses as spent.
                'amount' => $r->kind === 'income' ? (int) $r->amount : -(int) $r->amount,
            ])
            ->all());
    }

    /**
     * Trial balance (spec §15): debit and credit totals per account for the period.
     *
     * @return array{accounts: list<array{code: string, name: string, type: string, debit: int, credit: int}>, debit: int, credit: int, drift: int}
     */
    public function trialBalance(Company $company, Period $period): array
    {
        $accounts = array_values(Lines::in($company, $period)
            ->selectRaw('a.code, a.name, a.type, COALESCE(SUM(l.debit_paise), 0) as debit, COALESCE(SUM(l.credit_paise), 0) as credit')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->get()
            ->map(fn ($r) => ['code' => (string) $r->code, 'name' => (string) $r->name, 'type' => (string) $r->type, 'debit' => (int) $r->debit, 'credit' => (int) $r->credit])
            ->all());

        $debit = array_sum(array_column($accounts, 'debit'));
        $credit = array_sum(array_column($accounts, 'credit'));

        return ['accounts' => $accounts, 'debit' => $debit, 'credit' => $credit, 'drift' => $debit - $credit];
    }

    /**
     * Balance sheet as of a date (spec §15). Current-year profit is the open years' income
     * less expenses; FY close has already moved closed years into capital (D33).
     *
     * @return array{assets: list<array{name: string, amount: int}>, liabilities: list<array{name: string, amount: int}>, equity: list<array{name: string, amount: int}>, totalAssets: int, totalLiabilities: int, totalEquity: int, currentYearProfit: int, balanced: bool, drift: int}
     */
    public function balanceSheet(Company $company, CarbonImmutable $asOf): array
    {
        $rows = Lines::in($company, Period::asOf($asOf))
            ->selectRaw('a.id, a.code, a.name, a.role, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.role')
            ->orderBy('a.code')
            ->get();

        $assets = [];
        $liabilities = [];
        $equity = [];
        $profit = 0;

        foreach ($rows as $row) {
            $balance = (int) $row->balance; // debit-positive
            $role = AccountRole::from($row->role);

            if ($balance === 0 && $role !== AccountRole::Capital) {
                continue;
            }

            match ($role) {
                AccountRole::Money => $assets[] = ['name' => $row->name, 'amount' => $balance],
                // Partner current is an asset when the partner holds company money, a liability when the company owes them.
                AccountRole::PartnerCurrent => $balance > 0
                    ? $assets[] = ['name' => $row->name, 'amount' => $balance]
                    : $liabilities[] = ['name' => $row->name, 'amount' => -$balance],
                AccountRole::LoansPayable, AccountRole::ReimbursementsPayable => $liabilities[] = ['name' => $row->name, 'amount' => -$balance],
                AccountRole::Capital => $equity[] = ['name' => $row->name, 'amount' => -$balance],
                AccountRole::Income, AccountRole::Expense => $profit -= $balance,
            };
        }

        $equity[] = ['name' => 'Current-year profit', 'amount' => $profit];

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount'));
        $drift = $totalAssets - $totalLiabilities - $totalEquity;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'totalEquity' => $totalEquity,
            'currentYearProfit' => $profit,
            'balanced' => $drift === 0,
            'drift' => $drift,
        ];
    }

    /**
     * Capital statement for a period, normally a fiscal year (spec §11): per partner,
     * Opening + Contributions − Drawings + Share of profit = Closing. In a closed year the
     * share is what FY close posted; in an open year it's the allocation so far.
     *
     * @return list<array{partner_id: int, name: string, side_id: int|null, opening: int, contributions: int, drawings: int, profit: int, closing: int}>
     */
    public function capitalStatement(Company $company, Period $period): array
    {
        $start = $period->from;
        $before = $start === null ? [] : $this->capitalBy($company, Period::asOf($start->subDay()));

        $moves = Lines::in($company, $period)
            ->where('a.role', AccountRole::Capital->value)
            ->selectRaw('a.partner_id, e.type, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('a.partner_id', 'e.type')
            ->get();

        // Closed when FY close moved profit into capital (a reopened year's close nets to zero).
        $closed = $moves->contains(fn ($m) => $m->type === JournalType::FyClose->value && (int) $m->amount !== 0);
        $allocated = $closed ? [] : $this->allocator->allocate($company, $period);

        $partners = Partner::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('position')->orderBy('id')->get();
        $statement = [];

        foreach ($partners as $partner) {
            $mine = $moves->where('partner_id', $partner->id);
            $sum = fn (JournalType $type): int => (int) $mine->where('type', $type->value)->sum('amount');

            $opening = ($before[$partner->id] ?? 0) + $sum(JournalType::Opening);
            $contributions = $sum(JournalType::Contribution);
            $drawings = -$sum(JournalType::Drawing);
            $profit = $closed ? $sum(JournalType::FyClose) : ($allocated[$partner->id] ?? 0);

            if ($partner->archived_at !== null && $opening === 0 && $contributions === 0 && $drawings === 0 && $profit === 0) {
                continue;
            }

            $statement[] = [
                'partner_id' => $partner->id,
                'name' => $partner->name,
                'side_id' => $partner->side_id,
                'opening' => $opening,
                'contributions' => $contributions,
                'drawings' => $drawings,
                'profit' => $profit,
                'closing' => $opening + $contributions - $drawings + $profit,
            ];
        }

        return $statement;
    }

    /**
     * The headline figures (spec §4.3): income, expenses, net, salary, other, and the
     * current balance with its formula strip (opening + income − expenses ± capital & loans).
     *
     * `capitalAndLoans` is everything else that moved cash; it splits into `capital`
     * (contributions, drawings, loans and repayments) and `personal` (money partners or
     * members are owed for personal cards, less what partners hold personally), so the
     * strip can name every term (D58).
     *
     * @return array{income: int, expenses: int, net: int, salary: int, other: int, currentBalance: int, opening: int, capitalAndLoans: int, capital: int, personal: int}
     */
    public function figures(Company $company, Period $period): array
    {
        $pnl = $this->profitAndLoss($company, $period);
        $currentBalance = $this->moneyBalance($company, Period::asOf($period->to));
        $opening = ($period->from === null ? 0 : $this->moneyBalance($company, Period::asOf($period->from->subDay())))
            + $this->openingInPeriod($company, $period);

        $other = Lines::in($company, $period)
            ->whereNotIn('e.type', [JournalType::Opening->value, JournalType::FyClose->value])
            ->whereIn('a.role', [AccountRole::Capital->value, AccountRole::LoansPayable->value, AccountRole::PartnerCurrent->value, AccountRole::ReimbursementsPayable->value])
            ->selectRaw('a.role, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('a.role')
            ->pluck('amount', 'role')
            ->map(fn ($v) => (int) $v);

        return [
            ...$pnl,
            'currentBalance' => $currentBalance,
            'opening' => $opening,
            // Whatever else moved cash: capital, loans, personal cards and reimbursements.
            'capitalAndLoans' => $currentBalance - $opening - $pnl['net'],
            'capital' => ($other[AccountRole::Capital->value] ?? 0) + ($other[AccountRole::LoansPayable->value] ?? 0),
            'personal' => ($other[AccountRole::PartnerCurrent->value] ?? 0) + ($other[AccountRole::ReimbursementsPayable->value] ?? 0),
        ];
    }

    /**
     * Trial balance on Statements (spec §15): each account's net balance as of a date, in
     * the debit or credit column. Balance-sheet accounts run from the beginning; income and
     * expenses only from the start of the fiscal year, before closing (the year's own FY
     * close is left out on both sides, so a closed year still shows its income and
     * expenses). Profit of earlier years that were never closed shows as its own equity
     * row, so the columns always agree when every entry balances (D60).
     *
     * @return array{accounts: list<array{id: int|null, code: string, name: string, type: string, role: string|null, debit: int, credit: int}>, debit: int, credit: int, drift: int}
     */
    public function trialBalanceAsOf(Company $company, CarbonImmutable $fyStart, CarbonImmutable $asOf): array
    {
        $pnlRoles = [AccountRole::Income->value, AccountRole::Expense->value];
        $fromDay = $fyStart->toDateString();

        $select = 'a.id, a.code, a.name, a.type, a.role, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as net';
        $balance = Lines::in($company, Period::asOf($asOf))
            ->whereNotIn('a.role', $pnlRoles)
            ->where(fn ($q) => $q->where('e.type', '!=', JournalType::FyClose->value)->orWhere('l.date', '<', $fromDay))
            ->selectRaw($select)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.role');
        $profit = Lines::profitAndLoss($company, Period::between($fyStart, $asOf))
            ->selectRaw($select)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.role');

        $accounts = [];

        foreach ($balance->unionAll($profit)->get() as $row) {
            $net = (int) $row->net;
            $accounts[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'role' => (string) $row->role,
                'debit' => max($net, 0),
                'credit' => max(-$net, 0),
            ];
        }

        usort($accounts, fn (array $a, array $b) => strnatcmp($a['code'], $b['code']));

        $earlier = (int) (Lines::in($company, Period::asOf($fyStart->subDay()))
            ->whereIn('a.role', $pnlRoles)
            ->selectRaw('COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as net')
            ->value('net') ?? 0);

        if ($earlier !== 0) {
            $accounts[] = [
                'id' => null,
                'code' => '3900',
                'name' => 'Profit of earlier years, not yet closed',
                'type' => 'equity',
                'role' => null,
                'debit' => max($earlier, 0),
                'credit' => max(-$earlier, 0),
            ];
        }

        $debit = array_sum(array_column($accounts, 'debit'));
        $credit = array_sum(array_column($accounts, 'credit'));

        return ['accounts' => $accounts, 'debit' => $debit, 'credit' => $credit, 'drift' => $debit - $credit];
    }

    /** @return array<int, int> partner id → capital balance (credit-positive) */
    private function capitalBy(Company $company, Period $period): array
    {
        return Lines::in($company, $period)
            ->where('a.role', AccountRole::Capital->value)
            ->selectRaw('a.partner_id, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('a.partner_id')
            ->pluck('amount', 'partner_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function moneyBalance(Company $company, Period $period): int
    {
        return (int) (Lines::in($company, $period)
            ->where('a.role', AccountRole::Money->value)
            ->selectRaw('COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->value('balance') ?? 0);
    }

    private function openingInPeriod(Company $company, Period $period): int
    {
        return (int) (Lines::in($company, $period)
            ->where('a.role', AccountRole::Money->value)
            ->where('e.type', JournalType::Opening->value)
            ->selectRaw('COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as amount')
            ->value('amount') ?? 0);
    }

    /** @return Collection<int, stdClass> per date, role and salary flag */
    private function pnlRows(Company $company, Period $period): Collection
    {
        return Lines::profitAndLoss($company, $period)
            ->leftJoin('categories as c', 'c.id', '=', 'a.category_id')
            ->selectRaw('l.date, a.role, COALESCE(c.system, false) as is_salary, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('l.date', 'a.role', 'c.system')
            ->orderBy('l.date')
            ->get();
    }

    /**
     * @param  array{income: int, expenses: int, salary: int, other: int, net: int}  $totals
     * @return array{income: int, expenses: int, salary: int, other: int, net: int}
     */
    private function addRow(array $totals, stdClass $row): array
    {
        $amount = (int) $row->amount;

        if ($row->role === AccountRole::Income->value) {
            $totals['income'] += $amount;
        } else {
            $spent = -$amount;
            $totals['expenses'] += $spent;
            (bool) $row->is_salary ? $totals['salary'] += $spent : $totals['other'] += $spent;
        }

        $totals['net'] = $totals['income'] - $totals['expenses'];

        return $totals;
    }
}
