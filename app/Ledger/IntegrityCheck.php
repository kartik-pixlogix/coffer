<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ledger invariants (spec §4.5), checked for one company. The Pest invariant suite
 * and the nightly `coffer:ledger-integrity` job both use this, so they can't disagree.
 *
 * 1. Every journal entry balances.
 * 2. Trial balance: total debits = total credits, for any period.
 * 3. Balance sheet: assets = liabilities + equity, for any date.
 * 4. Partner profit shares sum to net profit.
 * 5. JV: settlement remainders sum to 0 (within ±1 paisa).
 * 6. Capital statement closing totals = the balance sheet's equity.
 */
final class IntegrityCheck
{
    public function __construct(
        private readonly Reports $reports,
        private readonly ProfitAllocator $allocator,
        private readonly SettlementCalculator $settlements,
    ) {}

    /** @return list<string> what's wrong, in words; empty when the books are sound */
    public function run(Company $company, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $failures = [];

        $unbalanced = DB::table('journal_lines')
            ->where('company_id', $company->id)
            ->selectRaw('journal_entry_id, SUM(debit_paise) - SUM(credit_paise) as drift')
            ->groupBy('journal_entry_id')
            ->havingRaw('SUM(debit_paise) - SUM(credit_paise) <> 0')
            ->pluck('drift', 'journal_entry_id');

        foreach ($unbalanced as $id => $drift) {
            $failures[] = "Invariant 1: entry #{$id} is out of balance by {$drift} paise.";
        }

        $years = FiscalYear::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('starts_on')->get();
        $dates = [$today, ...$years->map(fn (FiscalYear $y) => $y->ends_on)->all()];

        $trial = $this->reports->trialBalance($company, Period::asOf(max($dates)));

        if ($trial['drift'] !== 0) {
            $failures[] = "Invariant 2: the trial balance is out by {$trial['drift']} paise.";
        }

        foreach ($dates as $date) {
            $sheet = $this->reports->balanceSheet($company, $date);

            if (! $sheet['balanced']) {
                $failures[] = "Invariant 3: the balance sheet on {$date->toDateString()} is out by {$sheet['drift']} paise.";
            }
        }

        $allEarlierClosed = true;

        foreach ($years as $year) {
            $period = Period::fiscalYear($year);
            $net = Lines::netProfit($company, $period);
            $shares = array_sum($this->allocator->allocate($company, $period));

            if ($shares !== $net) {
                $failures[] = "Invariant 4: {$year->label} profit shares add up to {$shares}, not {$net} paise.";
            }

            if ($company->isJv()) {
                $sum = $this->settlements->calculate($company, $period)->remainderSum();

                if (abs($sum) > 1) {
                    $failures[] = "Invariant 5: {$year->label} settlement remainders add up to {$sum} paise.";
                }
            }

            // The statement's opening is capital before the year, so it matches the balance sheet
            // only when every earlier year's profit has been closed into capital.
            if ($allEarlierClosed) {
                $closing = array_sum(array_column($this->reports->capitalStatement($company, $period), 'closing'));
                $equity = $this->reports->balanceSheet($company, $year->ends_on)['totalEquity'];

                if ($closing !== $equity) {
                    $failures[] = "Invariant 6: {$year->label} capital closes at {$closing}, but equity is {$equity} paise.";
                }
            }

            $allEarlierClosed = $allEarlierClosed && $year->isClosed();
        }

        return $failures;
    }
}
