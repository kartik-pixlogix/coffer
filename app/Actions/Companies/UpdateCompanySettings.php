<?php

declare(strict_types=1);

namespace App\Actions\Companies;

use App\Enums\AttributionMode;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Ledger\OpeningBalance;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settings › Company (spec §3.2, D63). Saves identity, money, attribution and calendar
 * settings in one transaction, with the spec's locks:
 *
 * - the opening balance is editable until the first fiscal year is closed; a change
 *   reverses the opening entry and posts the new one;
 * - the currency is locked after the first transaction;
 * - the fiscal-year start month can change only while no year is closed; the open years'
 *   rows are rebuilt around the books' dates.
 */
final class UpdateCompanySettings
{
    public function __construct(
        private readonly OpeningBalance $opening,
        private readonly FiscalYears $years,
    ) {}

    /**
     * @param  array{name: string, legal_name: string|null, gstin: string|null, address: string|null, short_code: string, opening_balance_paise: int, currency: string, attribution_mode: string, fy_start_month: int, working_days: int, hours_per_day_centi: int}  $data
     * @return list<string> what changed, for the activity log
     */
    public function handle(Company $company, array $data, User $by): array
    {
        return DB::transaction(function () use ($company, $data, $by): array {
            if ($data['opening_balance_paise'] !== $company->opening_balance_paise && self::anyYearClosed($company)) {
                throw ValidationException::withMessages(['opening_balance' => 'The opening balance can’t change once a fiscal year is closed.']);
            }

            if ($data['currency'] !== $company->currency && self::hasTransactions($company)) {
                throw ValidationException::withMessages(['currency' => 'The currency is locked once the books have transactions.']);
            }

            if ($data['fy_start_month'] !== $company->fy_start_month && self::anyYearClosed($company)) {
                throw ValidationException::withMessages(['fy_start_month' => 'The fiscal year can’t move once a year is closed.']);
            }

            $openingChanged = $data['opening_balance_paise'] !== $company->opening_balance_paise;
            $monthChanged = $data['fy_start_month'] !== $company->fy_start_month;

            $company->fill([
                ...$data,
                'attribution_mode' => AttributionMode::from($data['attribution_mode']),
            ]);
            $changed = array_keys($company->getDirty());
            $company->save();

            if ($monthChanged) {
                $this->rebuildYears($company);
            }

            if ($openingChanged) {
                $this->opening->repost($company, $by);
            }

            return $changed;
        });
    }

    public static function anyYearClosed(Company $company): bool
    {
        return FiscalYear::query()->withoutGlobalScope('company')->where('company_id', $company->id)->whereNotNull('closed_at')->exists();
    }

    public static function hasTransactions(Company $company): bool
    {
        return JournalEntry::query()->withoutGlobalScope('company')->where('company_id', $company->id)->where('type', '!=', JournalType::Opening->value)->exists();
    }

    /** No year is closed, so the rows only label periods: rebuild them for the new start month. */
    private function rebuildYears(Company $company): void
    {
        FiscalYear::query()->withoutGlobalScope('company')->where('company_id', $company->id)->delete();

        $dates = JournalEntry::query()->withoutGlobalScope('company')->where('company_id', $company->id)
            ->selectRaw('MIN(date) as first, MAX(date) as last')->toBase()->first();

        $this->years->forDate($company, now());

        if ($dates?->first !== null) {
            for ($day = FiscalYears::startOf($company, CarbonImmutable::parse((string) $dates->first)); $day->lte(CarbonImmutable::parse((string) $dates->last)); $day = $day->addYear()) {
                $this->years->forDate($company, $day);
            }
        }
    }
}
