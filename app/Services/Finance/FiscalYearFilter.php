<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Ledger\FiscalYears;
use App\Ledger\Period;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;

/**
 * The "FY 2026-27" menu on finance pages: fiscal years from the first entry's year to
 * this one, and the one asked for with `?fy=2026` (this year by default).
 */
final readonly class FiscalYearFilter
{
    /** @param array<int, CarbonImmutable> $years */
    private function __construct(
        public Company $company,
        public CarbonImmutable $start,
        public Period $period,
        private array $years,
        private CarbonImmutable $today,
    ) {}

    public static function for(Company $company, mixed $asked, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $first = JournalEntry::query()->withoutGlobalScope('company')->where('company_id', $company->id)->min('date');
        $from = FiscalYears::startOf($company, $first !== null ? CarbonImmutable::parse((string) $first) : $today);
        $to = FiscalYears::startOf($company, $today);

        if ($from->greaterThan($to)) {
            $from = $to;
        }

        $years = [];

        for ($s = $from; $s->lessThanOrEqualTo($to); $s = $s->addYear()) {
            $years[$s->year] = $s;
        }

        $start = is_numeric($asked) && isset($years[(int) $asked]) ? $years[(int) $asked] : $to;

        return new self($company, $start, Period::between($start, $start->addYear()->subDay()), $years, $today);
    }

    public function isCurrent(): bool
    {
        return $this->period->contains($this->today);
    }

    /** The year so far: up to today in the current year, the whole year otherwise. */
    public function toDate(): Period
    {
        return $this->isCurrent() ? Period::between($this->start, $this->today) : $this->period;
    }

    /** The same span a year earlier (for the trend chip). */
    public function previous(): Period
    {
        $span = $this->toDate();

        return Period::between($this->start->subYear(), $span->to->subYear());
    }

    /** @return array{start: int, label: string, from: string, to: string, current: bool, options: list<array{value: int, label: string}>} */
    public function props(): array
    {
        return [
            'start' => $this->start->year,
            'label' => FiscalYears::label($this->company, $this->start),
            'from' => $this->start->toDateString(),
            'to' => $this->period->to->toDateString(),
            'current' => $this->isCurrent(),
            'options' => array_map(
                fn (int $year, CarbonImmutable $s) => ['value' => $year, 'label' => FiscalYears::label($this->company, $s)],
                array_keys($this->years),
                $this->years,
            ),
        ];
    }
}
