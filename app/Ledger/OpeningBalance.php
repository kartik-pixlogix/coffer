<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\JournalType;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The opening balance (spec §3.1, §4.2): cash in the company account on the first day of
 * the fiscal year, credited to partners' capital by effective equity.
 */
final class OpeningBalance
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly LedgerPoster $poster,
    ) {}

    /** Posts the company's opening balance, dated the start of the FY containing `$asOf` (today by default). */
    public function post(Company $company, ?CarbonImmutable $asOf = null, ?User $by = null): ?JournalEntry
    {
        $amount = $company->opening_balance_paise;

        if ($amount === 0) {
            return null;
        }

        $date = FiscalYears::startOf($company, $asOf ?? CarbonImmutable::now());
        $partners = Shares::partners($company)->keyBy('id');
        $shares = Rounding::largestRemainder($amount, Shares::effective($company));

        $lines = [LineData::debit($this->chart->money($this->chart->companyAccount($company)), $amount)];

        foreach ($shares as $partnerId => $share) {
            $partner = $partners[$partnerId];
            $lines[] = LineData::credit($this->chart->capital($partner), $share, partnerId: $partner->id, sideId: $partner->side_id);
        }

        return $this->poster->post($company, new EntryData(
            date: $date,
            type: JournalType::Opening,
            lines: $lines,
            memo: 'Opening balance',
            source: $company,
            postedBy: $by,
        ));
    }

    /** Settings › Company changed the opening balance: reverse the old entry, post the new one. */
    public function repost(Company $company, ?User $by = null): ?JournalEntry
    {
        return DB::transaction(function () use ($company, $by): ?JournalEntry {
            $current = $this->current($company);
            $asOf = $current?->date;

            if ($current !== null) {
                $this->poster->reverse($current, memo: 'Opening balance changed', by: $by);
            }

            return $this->post($company, $asOf, $by);
        });
    }

    public function current(Company $company): ?JournalEntry
    {
        return JournalEntry::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('type', JournalType::Opening->value)
            ->whereNull('reversed_by_id')
            ->latest('id')
            ->first();
    }
}
