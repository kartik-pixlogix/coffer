<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\JournalType;
use App\Ledger\Exceptions\LedgerException;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing and reopening a fiscal year (spec §4.6). Closing moves the year's profit into
 * capital by profit share (§4.4) with one entry dated the last day, then locks the year.
 * Reopening (Admin-only, audit-logged by the caller) reverses that entry and unlocks.
 */
final class YearEnd
{
    public function __construct(
        private readonly LedgerPoster $poster,
        private readonly ChartOfAccounts $chart,
        private readonly ProfitAllocator $allocator,
        private readonly Reports $reports,
        private readonly FiscalYears $fiscalYears,
        private readonly ReportCache $cache,
    ) {}

    /**
     * What closing would do: net profit, each partner's share and closing capital.
     *
     * @return array{net: int, shares: array<int, int>, capital: list<array<string, mixed>>}
     */
    public function preview(Company $company, FiscalYear $year): array
    {
        $period = Period::fiscalYear($year);

        return [
            'net' => Lines::netProfit($company, $period),
            'shares' => $this->allocator->allocate($company, $period),
            'capital' => $this->reports->capitalStatement($company, $period),
        ];
    }

    public function close(Company $company, FiscalYear $year, User $by): ?JournalEntry
    {
        return DB::transaction(function () use ($company, $year, $by): ?JournalEntry {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($year->id);

            if ($locked->isClosed()) {
                throw new LedgerException("{$locked->label} is already closed.");
            }

            $earlierOpen = FiscalYear::query()->withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->whereNull('closed_at')
                ->where('starts_on', '<', $locked->starts_on->toDateString())
                ->orderBy('starts_on')
                ->first();

            if ($earlierOpen !== null) {
                throw new LedgerException("Close {$earlierOpen->label} first.");
            }

            $entry = $this->postClosingEntry($company, $locked, $by);

            $locked->forceFill(['closed_at' => now(), 'closed_by' => $by->id, 'closing_entry_id' => $entry?->id])->save();

            // Capital carries forward on its own (capital accounts run on); the next year's
            // row exists so it shows as open straight away.
            $this->fiscalYears->forDate($company, $locked->ends_on->addDay());
            $this->cache->flush($company);

            return $entry;
        });
    }

    public function reopen(Company $company, FiscalYear $year, User $by): void
    {
        DB::transaction(function () use ($company, $year, $by): void {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->withoutGlobalScope('company')->lockForUpdate()->findOrFail($year->id);

            if (! $locked->isClosed()) {
                throw new LedgerException("{$locked->label} isn’t closed.");
            }

            $laterClosed = FiscalYear::query()->withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->whereNotNull('closed_at')
                ->where('starts_on', '>', $locked->starts_on->toDateString())
                ->orderByDesc('starts_on')
                ->first();

            if ($laterClosed !== null) {
                throw new LedgerException("Reopen {$laterClosed->label} first.");
            }

            // Unlock first: the reversal is dated inside this year.
            $locked->forceFill(['closed_at' => null, 'closed_by' => null])->save();

            if ($locked->closing_entry_id !== null) {
                $closing = JournalEntry::query()->withoutGlobalScope('company')->findOrFail($locked->closing_entry_id);
                $this->poster->reverse($closing, memo: "{$locked->label} reopened", by: $by);
                $locked->forceFill(['closing_entry_id' => null])->save();
            }
        });
    }

    private function postClosingEntry(Company $company, FiscalYear $year, User $by): ?JournalEntry
    {
        $period = Period::fiscalYear($year);

        // Zero every income and expense account for the year…
        $balances = Lines::profitAndLoss($company, $period)
            ->selectRaw('l.account_id, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as balance')
            ->groupBy('l.account_id')
            ->pluck('balance', 'account_id');

        $accounts = Account::query()->withoutGlobalScope('company')->whereIn('id', $balances->keys())->get()->keyBy('id');
        $lines = [];

        foreach ($balances as $accountId => $balance) {
            $balance = (int) $balance;
            $account = $accounts[$accountId];
            $lines[] = $balance > 0 ? LineData::credit($account, $balance) : LineData::debit($account, -$balance);
        }

        // …and move the profit into each partner's capital by their share.
        $partners = Partner::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get()->keyBy('id');

        foreach ($this->allocator->allocate($company, $period) as $partnerId => $share) {
            $partner = $partners[$partnerId];
            $capital = $this->chart->capital($partner);
            $lines[] = $share >= 0
                ? LineData::credit($capital, $share, partnerId: $partner->id, sideId: $partner->side_id)
                : LineData::debit($capital, -$share, partnerId: $partner->id, sideId: $partner->side_id);
        }

        $lines = array_values(array_filter($lines, fn (LineData $line) => ! $line->isEmpty()));

        if ($lines === []) {
            return null;
        }

        return $this->poster->post($company, new EntryData(
            date: $year->ends_on,
            type: JournalType::FyClose,
            lines: $lines,
            memo: "{$year->label} closed",
            postedBy: $by,
        ));
    }

    /** Used by tests and the integrity check: the year's income and expense balances after closing. */
    public static function profitAccountsZeroed(Company $company, FiscalYear $year): bool
    {
        return Lines::in($company, Period::fiscalYear($year))
            ->whereIn('a.role', [AccountRole::Income->value, AccountRole::Expense->value])
            ->selectRaw('l.account_id, SUM(l.debit_paise - l.credit_paise) as balance')
            ->groupBy('l.account_id')
            ->get()
            ->every(fn ($row) => (int) $row->balance === 0);
    }
}
