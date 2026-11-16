<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Ledger\CachedReports;
use App\Ledger\Period;
use App\Ledger\SettlementCalculator;
use App\Models\Company;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\TimeEntry;
use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * The dashboard's figures (Main.dc.html, spec §5) for a period, all from the cached ledger
 * aggregates so they agree with Reports, the statements and Capital accounts:
 * KPIs with the previous equal period, the balance hero and its formula strip (D58), the
 * monthly series, revenue by partner, expenses by category and the JV settlement alert.
 */
final class DashboardData
{
    /** Category slices before the rest are grouped. */
    private const int CATEGORY_SLICES = 4;

    public function __construct(
        private readonly CachedReports $reports,
        private readonly SettlementCalculator $settlements,
    ) {}

    /** @return array<string, mixed> */
    public function build(Company $company, ReportPeriod $period, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $span = $period->period;
        $now = $this->reports->figures($company, $span);
        $before = $this->reports->figures($company, $period->previous);
        [$hours, $members] = $this->hours($company, $span);
        [$prevHours] = $this->hours($company, $period->previous);

        $months = $this->months($company, $period, $today);
        $latest = null;
        foreach (array_reverse($months) as $m) {
            if ($m['income'] !== 0 || $m['expenses'] !== 0) {
                $latest = $m;
                break;
            }
        }

        $pending = Expense::query()->where('status', ExpenseStatus::Pending->value);

        return [
            'empty' => $this->isEmpty($company),
            'kpis' => [
                'income' => ['paise' => $now['income'], 'previous' => $before['income']],
                'expenses' => ['paise' => $now['expenses'], 'previous' => $before['expenses'], 'salary' => $now['salary']],
                'other' => ['paise' => $now['other'], 'previous' => $before['other']],
                'net' => ['paise' => $now['net'], 'previous' => $before['net']],
                'hours' => ['centi' => $hours, 'previous' => $prevHours, 'members' => $members],
                'pending' => ['paise' => (int) (clone $pending)->sum('amount_paise'), 'count' => (clone $pending)->count()],
            ],
            'balance' => [
                'currentPaise' => $now['currentBalance'],
                'openingPaise' => $now['opening'],
                'incomePaise' => $now['income'],
                'expensesPaise' => $now['expenses'],
                'capitalPaise' => $now['capital'],
                'personalPaise' => $now['personal'],
                'otherPaise' => $now['capitalAndLoans'] - $now['capital'] - $now['personal'],
                'asOf' => $span->to->toDateString(),
                'latestMonth' => $latest === null ? null : ['month' => $latest['month'], 'netPaise' => $latest['net']],
            ],
            'months' => $months,
            'selectedMonth' => $this->selectedMonth($months, $span),
            'revenueByPartner' => $this->revenueByPartner($company, $span, $now['income']),
            'expensesByCategory' => $this->expensesByCategory($company, $span, $now['expenses']),
            'settlement' => $company->isJv() ? $this->settlement($company, $period) : null,
        ];
    }

    /** A new company: nothing in the books but its opening balance, nothing waiting. */
    private function isEmpty(Company $company): bool
    {
        $entries = JournalEntry::query()->where('type', '!=', JournalType::Opening->value)->exists();

        return ! $entries && ! Expense::query()->exists() && ! TimeEntry::query()->exists();
    }

    /** @return array{0: int, 1: int} hours (hundredths) and members who logged them */
    private function hours(Company $company, Period $span): array
    {
        $row = TimeEntry::query()
            ->when($span->from !== null, fn ($q) => $q->where('date', '>=', $span->from?->toDateString()))
            ->where('date', '<=', $span->to->toDateString())
            ->selectRaw('COALESCE(SUM(hours_centi), 0) as centi, COUNT(DISTINCT member_id) as members')
            ->toBase()
            ->first();

        return [(int) ($row->centi ?? 0), (int) ($row->members ?? 0)];
    }

    /**
     * The charts' months (from the fiscal year's start to the period's end). The month in
     * progress is left off while nothing has happened in it yet.
     *
     * @return list<array{month: string, income: int, expenses: int, net: int}>
     */
    private function months(Company $company, ReportPeriod $period, CarbonImmutable $today): array
    {
        $names = $period->chartMonths();
        $from = CarbonImmutable::createFromFormat('!Y-m', $names[0] ?? $period->period->to->format('Y-m')) ?: $period->period->to;
        $rows = [];

        foreach ($this->reports->byMonth($company, Period::between($from, $period->period->to)) as $row) {
            $rows[$row['month']] = $row;
        }

        $months = array_map(fn (string $m) => [
            'month' => $m,
            'income' => $rows[$m]['income'] ?? 0,
            'expenses' => $rows[$m]['expenses'] ?? 0,
            'net' => $rows[$m]['net'] ?? 0,
        ], $names);

        $last = end($months);

        if (count($months) > 1 && $last !== false && $last['month'] === $today->format('Y-m') && $last['income'] === 0 && $last['expenses'] === 0) {
            array_pop($months);
        }

        return $months;
    }

    /**
     * The profit bar picked at first: the period's last month that's on the chart.
     *
     * @param  list<array{month: string, income: int, expenses: int, net: int}>  $months
     */
    private function selectedMonth(array $months, Period $span): ?int
    {
        $index = null;
        $from = ($span->from ?? $span->to)->format('Y-m');

        foreach ($months as $i => $m) {
            if ($m['month'] >= $from && $m['month'] <= $span->to->format('Y-m')) {
                $index = $i;
            }
        }

        return $index;
    }

    /** @return list<array{name: string, colour: int|null, paise: int}> */
    private function revenueByPartner(Company $company, Period $span, int $income): array
    {
        $figures = $this->reports->partnerFigures($company, $span);
        $partners = Partner::query()->orderBy('position')->orderBy('id')->get();
        $slices = [];

        foreach ($partners as $p) {
            $paise = $figures[$p->id]['revenue'] ?? 0;

            if ($p->archived_at === null || $paise !== 0) {
                $slices[] = ['name' => $p->name, 'colour' => $p->colour, 'paise' => $paise];
            }
        }

        // Income nobody brought in (JV pooled, or received without a partner).
        $rest = $income - array_sum(array_column($slices, 'paise'));

        if ($rest > 0) {
            $slices[] = ['name' => 'Not attributed', 'colour' => null, 'paise' => $rest];
        }

        return $slices;
    }

    /** @return list<array{name: string, paise: int}> */
    private function expensesByCategory(Company $company, Period $span, int $expenses): array
    {
        $rows = array_values(array_filter(
            $this->reports->byCategory($company, $span),
            fn (array $r) => $r['kind'] === 'expense' && $r['amount'] > 0,
        ));
        usort($rows, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        $slices = array_map(fn (array $r) => ['name' => $r['name'], 'paise' => $r['amount']], array_slice($rows, 0, self::CATEGORY_SLICES));
        $rest = $expenses - array_sum(array_column($slices, 'paise'));

        if (count($rows) > self::CATEGORY_SLICES && $rest > 0) {
            $slices[] = ['name' => 'Everything else', 'paise' => $rest];
        }

        return $slices;
    }

    /**
     * The JV alert (spec §5): who owes whom for the period's fiscal year, as Settlements
     * shows it. Null when every side is settled.
     *
     * @return array{transfers: list<array{fromName: string, toName: string, amountPaise: int}>, sides: int, shares: string, fromEntitledPaise: int, fromHoldsPaise: int}|null
     */
    private function settlement(Company $company, ReportPeriod $period): ?array
    {
        $result = $this->settlements->calculate($company, $period->fy->period);

        if ($result->transfers === []) {
            return null;
        }

        $first = $result->transfers[0];
        $from = $result->sides[$first['from']->id] ?? null;

        return [
            'transfers' => array_map(fn (array $t) => [
                'fromName' => $t['from']->name,
                'toName' => $t['to']->name,
                'amountPaise' => $t['amount'],
            ], $result->transfers),
            'sides' => count($result->sides),
            'shares' => implode('/', array_map(fn ($s) => (string) round($s->shareBps / 100), $result->sides)),
            'fromEntitledPaise' => $from === null ? 0 : $from->entitled,
            'fromHoldsPaise' => $from === null ? 0 : $from->netHeld + $from->pooled - $from->paidOut + $from->paidIn,
        ];
    }
}
