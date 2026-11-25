<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\CategoryKind;
use App\Enums\ScheduleLine;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The balance sheet and statement of profit and loss in the Schedule III format (Companies
 * Act 2013, Division I), adapted for a firm (D156): "Partners' funds" where a company has
 * shareholders' funds. Built from the same journal lines as Reports, so the totals tie: total
 * income and profit equal Reports::profitAndLoss, and both sides of the balance sheet equal
 * Reports::balanceSheet. Each line has a numbered note listing its accounts, and a figure
 * for the previous year when that year has entries.
 *
 * Balance sheet mapping:
 * - Partners' funds: capital accounts; reserves and surplus (profit not yet closed into capital).
 * - Current liabilities: short-term borrowings (loans from partners); other current
 *   liabilities (reimbursements payable, amounts due to partners).
 * - Current assets: cash and cash equivalents (money accounts); short-term loans and
 *   advances (partner current accounts holding company money).
 */
final class ScheduleThree
{
    /**
     * @return array{
     *     sections: list<array{title: string, groups: list<array{title: string|null, lines: list<array{label: string, note: int|null, amount: int, previous: int|null}>, total: int, previousTotal: int|null}>, total: int, previousTotal: int|null}>,
     *     notes: list<array{number: int, title: string, subtitle: string|null, rows: list<array{code: string, name: string, amount: int, previous: int|null}>, total: int, previousTotal: int|null}>,
     *     asOf: string, previousAsOf: string|null, totalAssets: int, totalEquityAndLiabilities: int
     * }
     */
    public function balanceSheet(Company $company, CarbonImmutable $asOf, CarbonImmutable $fyStart): array
    {
        $previousAsOf = $fyStart->subDay();
        $current = $this->balances($company, $asOf);
        $previous = $this->hasEntriesBefore($company, $fyStart) ? $this->balances($company, $previousAsOf) : null;

        $notes = new NoteBook($current, $previous);

        $capital = $notes->add("Partners' capital accounts", fn (array $a) => $a['role'] === AccountRole::Capital->value, credit: true);
        $reserves = $notes->addFigure('Reserves and surplus', 'Surplus in the statement of profit and loss (profit not yet closed into capital)', $this->profit($current), $previous === null ? null : $this->profit($previous));
        $borrowings = $notes->add('Short-term borrowings', fn (array $a) => $a['role'] === AccountRole::LoansPayable->value, credit: true, subtitle: 'Loans from partners, repayable on demand');
        $otherLiabilities = $notes->add('Other current liabilities', fn (array $a) => $a['role'] === AccountRole::ReimbursementsPayable->value
            || ($a['role'] === AccountRole::PartnerCurrent->value && $a['balance'] < 0), credit: true);
        $cash = $notes->add('Cash and cash equivalents', fn (array $a) => $a['role'] === AccountRole::Money->value, credit: false);
        $advances = $notes->add('Short-term loans and advances', fn (array $a) => $a['role'] === AccountRole::PartnerCurrent->value && $a['balance'] > 0, credit: false, subtitle: 'Amounts held by partners on behalf of the firm');

        $line = fn (array $note, string $label) => ['label' => $label, 'note' => $note['number'], 'amount' => $note['total'], 'previous' => $note['previousTotal']];

        $liabilities = $this->section('Equity and liabilities', [
            $this->group("Partners' funds", [$line($capital, "Partners' capital accounts"), $line($reserves, 'Reserves and surplus')]),
            $this->group('Current liabilities', [$line($borrowings, 'Short-term borrowings'), $line($otherLiabilities, 'Other current liabilities')]),
        ]);
        $assets = $this->section('Assets', [
            $this->group('Current assets', [$line($cash, 'Cash and cash equivalents'), $line($advances, 'Short-term loans and advances')]),
        ]);

        return [
            'sections' => [$liabilities, $assets],
            'notes' => $notes->all(),
            'asOf' => $asOf->toDateString(),
            'previousAsOf' => $previous === null ? null : $previousAsOf->toDateString(),
            'totalAssets' => $assets['total'],
            'totalEquityAndLiabilities' => $liabilities['total'],
        ];
    }

    /**
     * @return array{
     *     lines: list<array{key: string, label: string, note: int|null, amount: int|null, previous: int|null, strong: bool}>,
     *     notes: list<array{number: int, title: string, subtitle: string|null, rows: list<array{code: string, name: string, amount: int, previous: int|null}>, total: int, previousTotal: int|null}>,
     *     from: string, to: string, previousFrom: string|null, previousTo: string|null, totalIncome: int, totalExpenses: int, profit: int
     * }
     */
    public function profitAndLoss(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $previousFrom = $from->subYear();
        $previousTo = $to->subYear();
        $current = $this->movements($company, $from, $to);
        $previous = $this->hasEntriesBefore($company, $from, since: $previousFrom) ? $this->movements($company, $previousFrom, $previousTo) : null;
        $notes = new NoteBook($current, $previous);
        $byLine = [];

        foreach (ScheduleLine::cases() as $scheduleLine) {
            $credit = $scheduleLine->kind() === CategoryKind::Income;
            $byLine[$scheduleLine->value] = $notes->add($scheduleLine->label(), fn (array $a) => $a['line'] === $scheduleLine->value, credit: $credit, skipEmpty: true);
        }

        $figure = fn (?array $note): array => ['amount' => $note['total'] ?? 0, 'previous' => $note === null ? ($previous === null ? null : 0) : $note['previousTotal']];
        $row = fn (string $key, string $label, ?array $note, bool $strong = false) => ['key' => $key, 'label' => $label, 'note' => $note['number'] ?? null, ...$figure($note), 'strong' => $strong];
        $sum = function (array $rows) use ($previous): array {
            return [
                'amount' => array_sum(array_column($rows, 'amount')),
                'previous' => $previous === null ? null : array_sum(array_map(fn (array $r) => (int) $r['previous'], $rows)),
            ];
        };

        $revenue = $row('revenue', 'I. Revenue from operations', $byLine[ScheduleLine::RevenueFromOperations->value]);
        $other = $row('other_income', 'II. Other income', $byLine[ScheduleLine::OtherIncome->value]);
        $income = ['key' => 'total_income', 'label' => 'III. Total income (I + II)', 'note' => null, ...$sum([$revenue, $other]), 'strong' => true];

        $expenses = [];

        foreach ([ScheduleLine::EmployeeBenefits, ScheduleLine::FinanceCosts, ScheduleLine::Depreciation, ScheduleLine::OtherExpenses] as $scheduleLine) {
            $expenses[] = $row($scheduleLine->value, $scheduleLine->label(), $byLine[$scheduleLine->value]);
        }

        $totalExpenses = ['key' => 'total_expenses', 'label' => 'Total expenses (IV)', 'note' => null, ...$sum($expenses), 'strong' => true];
        $beforeTax = [
            'key' => 'profit_before_tax',
            'label' => 'V. Profit before tax (III − IV)',
            'note' => null,
            'amount' => $income['amount'] - $totalExpenses['amount'],
            'previous' => $previous === null ? null : (int) $income['previous'] - (int) $totalExpenses['previous'],
            'strong' => true,
        ];

        $lines = [
            $revenue,
            $other,
            $income,
            ['key' => 'expenses_heading', 'label' => 'IV. Expenses', 'note' => null, 'amount' => null, 'previous' => null, 'strong' => false],
            ...$expenses,
            $totalExpenses,
            $beforeTax,
            ['key' => 'tax', 'label' => 'VI. Tax expense', 'note' => null, 'amount' => 0, 'previous' => $previous === null ? null : 0, 'strong' => false],
            [...$beforeTax, 'key' => 'profit', 'label' => 'VII. Profit for the period (V − VI)'],
        ];

        return [
            'lines' => $lines,
            'notes' => $notes->all(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'previousFrom' => $previous === null ? null : $previousFrom->toDateString(),
            'previousTo' => $previous === null ? null : $previousTo->toDateString(),
            'totalIncome' => $income['amount'],
            'totalExpenses' => $totalExpenses['amount'],
            'profit' => $beforeTax['amount'],
        ];
    }

    /**
     * @param  list<array{label: string, note: int|null, amount: int, previous: int|null}>  $lines
     * @return array{title: string|null, lines: list<array{label: string, note: int|null, amount: int, previous: int|null}>, total: int, previousTotal: int|null}
     */
    private function group(?string $title, array $lines): array
    {
        [$total, $previous] = $this->totals(array_column($lines, 'amount'), array_column($lines, 'previous'));

        return ['title' => $title, 'lines' => $lines, 'total' => $total, 'previousTotal' => $previous];
    }

    /**
     * @param  list<array{title: string|null, lines: list<array{label: string, note: int|null, amount: int, previous: int|null}>, total: int, previousTotal: int|null}>  $groups
     * @return array{title: string, groups: list<array{title: string|null, lines: list<array{label: string, note: int|null, amount: int, previous: int|null}>, total: int, previousTotal: int|null}>, total: int, previousTotal: int|null}
     */
    private function section(string $title, array $groups): array
    {
        [$total, $previous] = $this->totals(array_column($groups, 'total'), array_column($groups, 'previousTotal'));

        return ['title' => $title, 'groups' => $groups, 'total' => $total, 'previousTotal' => $previous];
    }

    /**
     * This period's total, and the previous one's (null when any part has no previous figure).
     *
     * @param  list<int>  $amounts
     * @param  list<int|null>  $previous
     * @return array{0: int, 1: int|null}
     */
    private function totals(array $amounts, array $previous): array
    {
        $total = 0;
        $before = 0;

        foreach ($amounts as $amount) {
            $total += $amount;
        }

        foreach ($previous as $amount) {
            if ($amount === null) {
                return [$total, null];
            }

            $before += $amount;
        }

        return [$total, $before];
    }

    /**
     * Every account's balance as of a date (debit positive), as Reports::balanceSheet reads it.
     *
     * @return list<array{id: int, code: string, name: string, role: string, line: string|null, balance: int}>
     */
    private function balances(Company $company, CarbonImmutable $asOf): array
    {
        return array_values(Lines::in($company, Period::asOf($asOf))
            ->selectRaw('a.id, a.code, a.name, a.role, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.role')
            ->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'role' => (string) $r->role, 'line' => null, 'balance' => (int) $r->balance])
            ->sortBy('code', SORT_NATURAL)->values()->all());
    }

    /**
     * Income and expense accounts' movement in a period (debit positive), with each one's
     * Schedule III line.
     *
     * @return list<array{id: int, code: string, name: string, role: string, line: string|null, balance: int}>
     */
    private function movements(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(Lines::profitAndLoss($company, Period::between($from, $to))
            ->join('categories as c', 'c.id', '=', 'a.category_id')
            ->selectRaw('a.id, a.code, a.name, a.role, c.kind, c.name as category, c.system, c.schedule_line, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.role', 'c.kind', 'c.name', 'c.system', 'c.schedule_line')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'code' => (string) $r->code,
                'name' => (string) $r->name,
                'role' => (string) $r->role,
                'line' => is_string($r->schedule_line) && ScheduleLine::tryFrom($r->schedule_line) !== null
                    ? $r->schedule_line
                    : ScheduleLine::defaultFor(CategoryKind::from((string) $r->kind), (string) $r->category, (bool) $r->system)->value,
                'balance' => (int) $r->balance,
            ])
            ->sortBy('code', SORT_NATURAL)->values()->all());
    }

    /** @param list<array{role: string, balance: int}> $balances profit not yet closed into capital (credit positive) */
    private function profit(array $balances): int
    {
        $profit = 0;

        foreach ($balances as $a) {
            if (in_array($a['role'], [AccountRole::Income->value, AccountRole::Expense->value], true)) {
                $profit -= $a['balance'];
            }
        }

        return $profit;
    }

    private function hasEntriesBefore(Company $company, CarbonImmutable $date, ?CarbonImmutable $since = null): bool
    {
        return Lines::in($company, $since === null ? Period::asOf($date->subDay()) : Period::between($since, $date->subDay()))->exists();
    }
}
