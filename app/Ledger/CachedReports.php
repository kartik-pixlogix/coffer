<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The report aggregates pages read (Dashboard, Reports, Statements), cached per company
 * and period through ReportCache (D62). Same answers as Reports / PartnerMetrics /
 * ProfitAllocator, which the reconciliation test checks; any posting invalidates them.
 */
final class CachedReports
{
    public function __construct(
        private readonly ReportCache $cache,
        private readonly Reports $reports,
        private readonly PartnerMetrics $metrics,
        private readonly ProfitAllocator $allocator,
    ) {}

    /** @return array{income: int, expenses: int, net: int, salary: int, other: int, currentBalance: int, opening: int, capitalAndLoans: int, capital: int, personal: int} */
    public function figures(Company $company, Period $period): array
    {
        return $this->cache->remember($company, 'figures', $this->key($period), fn () => $this->reports->figures($company, $period));
    }

    /** @return list<array{month: string, income: int, expenses: int, salary: int, other: int, net: int}> */
    public function byMonth(Company $company, Period $period): array
    {
        return $this->cache->remember($company, 'by-month', $this->key($period), fn () => $this->reports->profitAndLossByMonth($company, $period));
    }

    /** @return list<array{category_id: int, name: string, kind: string, amount: int}> */
    public function byCategory(Company $company, Period $period): array
    {
        return $this->cache->remember($company, 'by-category', $this->key($period), fn () => $this->reports->byCategory($company, $period));
    }

    /** @return list<array{partner_id: int, name: string, side_id: int|null, opening: int, contributions: int, drawings: int, profit: int, closing: int}> */
    public function capitalStatement(Company $company, Period $period): array
    {
        return $this->cache->remember($company, 'capital', $this->key($period), fn () => $this->reports->capitalStatement($company, $period));
    }

    /** @return array{assets: list<array{name: string, amount: int}>, liabilities: list<array{name: string, amount: int}>, equity: list<array{name: string, amount: int}>, totalAssets: int, totalLiabilities: int, totalEquity: int, currentYearProfit: int, balanced: bool, drift: int} */
    public function balanceSheet(Company $company, CarbonImmutable $asOf): array
    {
        return $this->cache->remember($company, 'balance-sheet', [$asOf->toDateString()], fn () => $this->reports->balanceSheet($company, $asOf));
    }

    /** @return array{accounts: list<array{id: int|null, code: string, name: string, type: string, role: string|null, debit: int, credit: int}>, debit: int, credit: int, drift: int} */
    public function trialBalance(Company $company, CarbonImmutable $fyStart, CarbonImmutable $asOf): array
    {
        return $this->cache->remember($company, 'trial-balance', [$fyStart->toDateString(), $asOf->toDateString()], fn () => $this->reports->trialBalanceAsOf($company, $fyStart, $asOf));
    }

    /**
     * Ledger figures per partner (revenue, direct, other, salary). Hours come from
     * timesheets, not the books, so they aren't cached here.
     *
     * @return array<int, array{revenue: int, direct: int, other: int, salary: int}>
     */
    public function partnerFigures(Company $company, Period $period): array
    {
        return $this->cache->remember($company, 'partners', $this->key($period), fn () => array_map(
            fn (array $f) => ['revenue' => $f['revenue'], 'direct' => $f['direct'], 'other' => $f['other'], 'salary' => $f['salary']],
            $this->metrics->forPeriod($company, $period),
        ));
    }

    /** @return array<int, int> partner id → share of profit (spec §4.4) */
    public function profitShares(Company $company, Period $period): array
    {
        return $this->cache->remember($company, 'shares', $this->key($period), fn () => $this->allocator->allocate($company, $period));
    }

    /** @return list<string|null> */
    private function key(Period $period): array
    {
        return [$period->from?->toDateString(), $period->to->toDateString()];
    }
}
