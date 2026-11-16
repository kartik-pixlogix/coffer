<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Fiscal years (spec §1, §4.6): April–March by default, the start month set per company.
 * Rows are created the first time anything is dated inside them.
 */
final class FiscalYears
{
    /** First day of the fiscal year containing `$date`. */
    public static function startOf(Company $company, DateTimeInterface $date): CarbonImmutable
    {
        $day = CarbonImmutable::instance($date)->startOfDay();
        $month = $company->fy_start_month;
        $year = $day->month >= $month ? $day->year : $day->year - 1;

        return CarbonImmutable::create($year, $month, 1)->startOfDay();
    }

    /** "FY 2026-27", or "FY 2026" when the year starts in January. */
    public static function label(Company $company, CarbonImmutable $start): string
    {
        return $company->fy_start_month === 1
            ? 'FY '.$start->year
            : 'FY '.$start->year.'-'.str_pad((string) (($start->year + 1) % 100), 2, '0', STR_PAD_LEFT);
    }

    public function forDate(Company $company, DateTimeInterface $date): FiscalYear
    {
        $start = self::startOf($company, $date);

        /** @var FiscalYear */
        return FiscalYear::query()->withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'starts_on' => $start->toDateString()],
            ['ends_on' => $start->addYear()->subDay()->toDateString(), 'label' => self::label($company, $start)],
        );
    }

    /** The closed fiscal year containing `$date`, if any (never creates a row). */
    public function closedYearFor(Company $company, DateTimeInterface $date): ?FiscalYear
    {
        $day = CarbonImmutable::instance($date)->toDateString();

        return FiscalYear::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereNotNull('closed_at')
            ->where('starts_on', '<=', $day)
            ->where('ends_on', '>=', $day)
            ->first();
    }

    public function isLocked(Company $company, DateTimeInterface $date): bool
    {
        return $this->closedYearFor($company, $date) !== null;
    }
}
