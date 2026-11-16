<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AttributionMode;
use App\Ledger\CachedReports;
use App\Ledger\FiscalYears;
use App\Ledger\Period;
use App\Models\Company;
use App\Models\Partner;
use App\Support\Equity;

/**
 * Everything Reports shows and exports (Reports.dc.html, spec §15): KPIs, ratios, the
 * monthly profit bars, the month-by-month table and partner P&L, read from the cached
 * ledger aggregates. Percentages are basis points (2,970 = 29.7%) so nothing is a float.
 */
final class ProfitAndLossReport
{
    public function __construct(private readonly CachedReports $reports) {}

    /**
     * @return array{
     *     company: string, range: string, fyLabel: string, attribution: string,
     *     kpis: array{income: int, expenses: int, salary: int, other: int, net: int, marginBps: int|null, expenseRatioBps: int|null, months: int, avgIncome: int, avgExpenses: int},
     *     bars: list<array{month: string, net: int, income: int, inPeriod: bool}>,
     *     months: list<array{month: string, income: int, expenses: int, other: int, salary: int, net: int, marginBps: int|null}>,
     *     totals: array{income: int, expenses: int, other: int, salary: int, net: int, marginBps: int|null},
     *     partners: list<array{id: int, name: string, colour: int, side: string|null, sideShareBps: int|null, equityBps: int, revenue: int, expenses: int, other: int, net: int, profitDue: int, revenueShareBps: int|null}>,
     *     partnerTotals: array{equityBps: int, revenue: int, expenses: int, other: int, net: int, profitDue: int}
     * }
     */
    public function build(Company $company, ReportPeriod $period): array
    {
        $span = $period->period;
        $figures = $this->reports->figures($company, $span);
        $n = $period->monthCount();
        $expenses = $figures['expenses'];

        $inPeriod = [];
        foreach ($this->reports->byMonth($company, $span) as $row) {
            $inPeriod[$row['month']] = $row;
        }

        $months = [];
        foreach ($this->monthsOf($span) as $month) {
            $row = $inPeriod[$month] ?? ['income' => 0, 'expenses' => 0, 'other' => 0, 'salary' => 0, 'net' => 0];
            $months[] = [
                'month' => $month,
                'income' => $row['income'],
                'expenses' => $row['expenses'],
                'other' => $row['other'],
                'salary' => $row['salary'],
                'net' => $row['net'],
                'marginBps' => self::ratio($row['net'], $row['income']),
            ];
        }

        // Bars run over the fiscal year so far; the period's months are highlighted.
        $chartFrom = $period->period->from ?? $period->fyStart();
        $chartFrom = $chartFrom->lt($period->fyStart()) ? $chartFrom : $period->fyStart();
        $chartTo = $period->fy->toDate()->to->gt($span->to) ? $period->fy->toDate()->to : $span->to;
        $chart = [];
        foreach ($this->reports->byMonth($company, Period::between($chartFrom, $chartTo)) as $row) {
            $chart[$row['month']] = $row;
        }
        $bars = [];
        foreach ($this->monthsOf(Period::between($chartFrom, $chartTo)) as $month) {
            $bars[] = [
                'month' => $month,
                'net' => $chart[$month]['net'] ?? 0,
                'income' => $chart[$month]['income'] ?? 0,
                'inPeriod' => in_array($month, array_column($months, 'month'), true),
            ];
        }

        [$partners, $partnerTotals] = $this->partners($company, $span, $figures['income']);

        return [
            'company' => $company->name,
            'range' => ReportPeriod::rangeLabel($span),
            'fyLabel' => FiscalYears::label($company, $period->fyStart()),
            'attribution' => $company->attribution_mode->value,
            'kpis' => [
                'income' => $figures['income'],
                'expenses' => $expenses,
                'salary' => $figures['salary'],
                'other' => $figures['other'],
                'net' => $figures['net'],
                'marginBps' => self::ratio($figures['net'], $figures['income']),
                'expenseRatioBps' => self::ratio($expenses, $figures['income']),
                'months' => $n,
                'avgIncome' => self::divide($figures['income'], $n),
                'avgExpenses' => self::divide($expenses, $n),
            ],
            'bars' => $bars,
            'months' => $months,
            'totals' => [
                'income' => $figures['income'],
                'expenses' => $expenses,
                'other' => $figures['other'],
                'salary' => $figures['salary'],
                'net' => $figures['net'],
                'marginBps' => self::ratio($figures['net'], $figures['income']),
            ],
            'partners' => $partners,
            'partnerTotals' => $partnerTotals,
        ];
    }

    /** Basis points of `$part` over `$whole`, rounded half away from zero; null when the whole is 0. */
    public static function ratio(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : self::divide($part * Equity::WHOLE, $whole);
    }

    /** Integer division rounded half away from zero. */
    public static function divide(int $value, int $by): int
    {
        if ($by === 0) {
            return 0;
        }

        $q = intdiv($value, $by);
        $r = $value % $by;

        return 2 * abs($r) >= abs($by) ? $q + (($value < 0) === ($by < 0) ? 1 : -1) : $q;
    }

    /** @return list<string> */
    private function monthsOf(Period $span): array
    {
        $months = [];
        $from = ($span->from ?? $span->to)->startOfMonth();

        for ($m = $from; $m->lte($span->to); $m = $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /**
     * Partner P&L (spec §15, D39): revenue brought in; expenses on their line including the
     * salaries of members billed to them; their share of shared costs; net contribution;
     * profit due (§4.4); and their share of revenue.
     *
     * @return array{0: list<array{id: int, name: string, colour: int, side: string|null, sideShareBps: int|null, equityBps: int, revenue: int, expenses: int, other: int, net: int, profitDue: int, revenueShareBps: int|null}>, 1: array{equityBps: int, revenue: int, expenses: int, other: int, net: int, profitDue: int}}
     */
    private function partners(Company $company, Period $span, int $income): array
    {
        $figures = $this->reports->partnerFigures($company, $span);
        $shares = $this->reports->profitShares($company, $span);

        $partners = Partner::query()->withoutGlobalScope('company')
            ->with(['side' => fn ($q) => $q->withoutGlobalScope('company')])
            ->where('company_id', $company->id)
            ->orderBy('position')->orderBy('id')
            ->get()
            ->filter(fn (Partner $p) => $p->archived_at === null || isset($figures[$p->id]) || isset($shares[$p->id]));

        $rows = [];

        foreach ($partners as $p) {
            $f = $figures[$p->id] ?? ['revenue' => 0, 'direct' => 0, 'other' => 0, 'salary' => 0];
            $expenses = $f['direct'] + $f['salary'];
            $rows[] = [
                'id' => $p->id,
                'name' => $p->name,
                'colour' => $p->colour,
                'side' => $p->side?->name,
                'sideShareBps' => $p->side !== null ? $p->equity_bps : null,
                'equityBps' => $p->archived_at !== null ? 0 : ($p->side === null ? $p->equity_bps : intdiv($p->side->equity_bps * $p->equity_bps, Equity::WHOLE)),
                'revenue' => $f['revenue'],
                'expenses' => $expenses,
                'other' => $f['other'],
                'net' => $f['revenue'] - $expenses - $f['other'],
                'profitDue' => $shares[$p->id] ?? 0,
                'revenueShareBps' => self::ratio($f['revenue'], $income),
            ];
        }

        $sum = function (string $key) use ($rows): int {
            $total = 0;

            foreach ($rows as $row) {
                $total += (int) $row[$key];
            }

            return $total;
        };

        return [$rows, [
            'equityBps' => $sum('equityBps'),
            'revenue' => $sum('revenue'),
            'expenses' => $sum('expenses'),
            'other' => $sum('other'),
            'net' => $sum('net'),
            'profitDue' => $sum('profitDue'),
        ]];
    }

    /** Whether profit due follows equity only (JV pooled) or attribution (spec §4.4). */
    public static function pooled(Company $company): bool
    {
        return $company->attribution_mode === AttributionMode::JvPooled;
    }
}
