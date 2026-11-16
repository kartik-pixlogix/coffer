<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Ledger\FiscalYears;
use App\Ledger\Period;
use App\Models\Company;
use App\Services\Finance\FiscalYearFilter;
use Carbon\CarbonImmutable;

/**
 * The period control on Dashboard and Reports (spec §5, §15, D59): a fiscal year to date
 * (the default), one of its quarters, a month, or a custom range, read from the query
 * string (`?fy=2026&period=q2`, `?period=month&month=2026-10`, `?period=custom&from=…&to=…`).
 * Spans in the current year stop at today. `previous` is the equal period before it, for
 * the trend chips: the same span a year earlier for a fiscal year, the quarter or month
 * before, or the same number of days before a custom range.
 */
final readonly class ReportPeriod
{
    public const array PRESETS = ['fy', 'q1', 'q2', 'q3', 'q4', 'month', 'custom'];

    /** The longest custom range, in days (a few years of books). */
    public const int MAX_CUSTOM_DAYS = 1_830;

    private function __construct(
        public Company $company,
        public string $preset,
        public FiscalYearFilter $fy,
        public Period $period,
        public Period $previous,
        public ?string $month,
        private CarbonImmutable $today,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(Company $company, array $query, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $preset = is_string($query['period'] ?? null) && in_array($query['period'], self::PRESETS, true) ? $query['period'] : 'fy';

        if ($preset === 'custom') {
            $from = self::date($query['from'] ?? null);
            $to = self::date($query['to'] ?? null);

            if ($from !== null && $to !== null && $from->lte($to) && $from->diffInDays($to) <= self::MAX_CUSTOM_DAYS) {
                $fy = FiscalYearFilter::for($company, FiscalYears::startOf($company, $from)->year, $today);
                $days = (int) $from->diffInDays($to) + 1;

                return new self($company, 'custom', $fy, Period::between($from, $to), Period::between($from->subDays($days), $from->subDay()), null, $today);
            }

            $preset = 'fy';
        }

        if ($preset === 'month') {
            $month = is_string($query['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $query['month']) === 1
                ? CarbonImmutable::createFromFormat('!Y-m', $query['month'])
                : null;

            if ($month instanceof CarbonImmutable && $month->lte($today)) {
                $fy = FiscalYearFilter::for($company, FiscalYears::startOf($company, $month)->year, $today);
                $end = $month->endOfMonth()->startOfDay();
                $to = $end->gt($today) ? $today : $end;
                $prevStart = $month->subMonthNoOverflow();
                $prevTo = $to->eq($end) ? $prevStart->endOfMonth()->startOfDay() : $to->subMonthNoOverflow();

                return new self($company, 'month', $fy, Period::between($month, $to), Period::between($prevStart, $prevTo), $month->format('Y-m'), $today);
            }

            $preset = 'fy';
        }

        $fy = FiscalYearFilter::for($company, $query['fy'] ?? null, $today);

        if (str_starts_with($preset, 'q')) {
            $n = (int) substr($preset, 1);
            $start = $fy->start->addMonthsNoOverflow(3 * ($n - 1));

            if ($start->lte($today)) {
                $end = $start->addMonthsNoOverflow(3)->subDay();
                $to = $end->gt($today) ? $today : $end;

                return new self($company, $preset, $fy, Period::between($start, $to), Period::between($start->subMonthsNoOverflow(3), $to->eq($end) ? $start->subDay() : $to->subMonthsNoOverflow(3)), null, $today);
            }
        }

        return new self($company, 'fy', $fy, $fy->toDate(), $fy->previous(), null, $today);
    }

    /** The period's fiscal year start (where monthly charts begin). */
    public function fyStart(): CarbonImmutable
    {
        return $this->fy->start;
    }

    /**
     * Months the charts show: from the start of the period's fiscal year (or the period, if
     * earlier) to the period's last month.
     *
     * @return list<string> "2026-04"…
     */
    public function chartMonths(): array
    {
        $from = $this->period->from ?? $this->fy->start;
        $start = ($from->lt($this->fy->start) ? $from : $this->fy->start)->startOfMonth();
        $months = [];

        for ($m = $start; $m->lte($this->period->to); $m = $m->addMonthNoOverflow()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /** Months the period covers (for averages): "over N months". */
    public function monthCount(): int
    {
        $from = $this->period->from ?? $this->fy->start;

        return max(1, ($this->period->to->year - $from->year) * 12 + $this->period->to->month - $from->month + 1);
    }

    /** "1 Apr – 15 Nov 2026", "1 – 31 Oct 2026", "1 Apr 2025 – 31 Mar 2026". */
    public static function rangeLabel(Period $period): string
    {
        $from = $period->from ?? $period->to;
        $to = $period->to;

        if ($from->year !== $to->year) {
            return $from->format('j M Y').' – '.$to->format('j M Y');
        }

        if ($from->month === $to->month) {
            return $from->day === $to->day ? $to->format('j M Y') : $from->format('j').' – '.$to->format('j M Y');
        }

        return $from->format('j M').' – '.$to->format('j M Y');
    }

    /** What the trend chips compare with: "the same span of FY 2025-26", "Q1", "September". */
    public function comparedTo(): string
    {
        return match (true) {
            $this->preset === 'fy' => 'the same span of '.FiscalYears::label($this->company, $this->fy->start->subYear()),
            $this->preset === 'month' => ($this->previous->from ?? $this->previous->to)->format('F'),
            str_starts_with($this->preset, 'q') => $this->preset === 'q1' ? 'Q4 of '.FiscalYears::label($this->company, $this->fy->start->subYear()) : 'Q'.((int) substr($this->preset, 1) - 1),
            default => 'the '.((int) ($this->previous->from ?? $this->previous->to)->diffInDays($this->previous->to) + 1).' days before',
        };
    }

    /**
     * Query values that reproduce this period in a link (exports, Resolve).
     *
     * @return array<string, int|string|null>
     */
    public function query(): array
    {
        return match ($this->preset) {
            'custom' => ['period' => 'custom', 'from' => $this->period->from?->toDateString(), 'to' => $this->period->to->toDateString()],
            'month' => ['period' => 'month', 'month' => $this->month],
            'fy' => ['fy' => $this->fy->start->year],
            default => ['fy' => $this->fy->start->year, 'period' => $this->preset],
        };
    }

    /**
     * @return array{preset: string, from: string, to: string, label: string, month: string|null, comparedTo: string, fy: array<string, mixed>, quarters: list<array{value: string, label: string, range: string}>, months: list<array{value: string, label: string}>}
     */
    public function props(): array
    {
        $quarters = [];

        for ($n = 1; $n <= 4; $n++) {
            $start = $this->fy->start->addMonthsNoOverflow(3 * ($n - 1));

            if ($start->lte($this->today)) {
                $end = $start->addMonthsNoOverflow(3)->subDay();
                $quarters[] = ['value' => 'q'.$n, 'label' => 'Q'.$n, 'range' => self::rangeLabel(Period::between($start, $end->gt($this->today) ? $this->today : $end))];
            }
        }

        $months = [];

        for ($m = $this->fy->start; $m->lte($this->today) && $m->lte($this->fy->period->to); $m = $m->addMonthNoOverflow()) {
            $months[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        return [
            'preset' => $this->preset,
            'from' => ($this->period->from ?? $this->fy->start)->toDateString(),
            'to' => $this->period->to->toDateString(),
            'label' => self::rangeLabel($this->period),
            'month' => $this->month,
            'comparedTo' => $this->comparedTo(),
            'fy' => $this->fy->props(),
            'quarters' => $quarters,
            'months' => $months,
        ];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
