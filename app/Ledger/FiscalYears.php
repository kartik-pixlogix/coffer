<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

        $key = ['company_id' => $company->id, 'starts_on' => $start->toDateString()];
        $query = fn () => FiscalYear::query()->withoutGlobalScope('company')->where($key);

        if (($year = $query()->first()) !== null) {
            return $year;
        }

        // Two postings can open the same year at once: the loser reads the winner's row. The
        // insert runs in a savepoint (PostgreSQL), and the re-read locks so it sees the commit.
        try {
            /** @var FiscalYear */
            return DB::transaction(fn () => FiscalYear::query()->withoutGlobalScope('company')->create([
                ...$key,
                'ends_on' => $start->addYear()->subDay()->toDateString(),
                'label' => self::label($company, $start),
            ]));
        } catch (UniqueConstraintViolationException) {
            /** @var FiscalYear */
            return $query()->lockForUpdate()->firstOrFail();
        }
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
