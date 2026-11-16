<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** A date range for figures, inclusive on both ends. No start means "since the beginning". */
final readonly class Period
{
    public function __construct(
        public ?CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return new self(CarbonImmutable::instance($from)->startOfDay(), CarbonImmutable::instance($to)->startOfDay());
    }

    /** Everything up to and including `$date` (balance sheet "as of"). */
    public static function asOf(DateTimeInterface $date): self
    {
        return new self(null, CarbonImmutable::instance($date)->startOfDay());
    }

    public static function fiscalYear(FiscalYear $year): self
    {
        return self::between($year->starts_on, $year->ends_on);
    }

    /** The fiscal year containing `$date`. */
    public static function fiscalYearOf(Company $company, DateTimeInterface $date): self
    {
        $start = FiscalYears::startOf($company, $date);

        return self::between($start, $start->addYear()->subDay());
    }

    public function contains(DateTimeInterface $date): bool
    {
        $day = CarbonImmutable::instance($date)->startOfDay();

        return ($this->from === null || $day->gte($this->from)) && $day->lte($this->to);
    }
}
