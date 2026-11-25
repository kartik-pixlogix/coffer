<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Services\Finance\FiscalYearFilter;
use Carbon\CarbonImmutable;

/**
 * The period of a statement (Statements.dc.html): `?fy=2026&from=2026-04-01&asof=2026-11-15`.
 * Both dates stay inside the chosen year. "As of" defaults to today in the current year and
 * the last day of a past one; "from" (D155: the four-column trial balance, cash flow, P&L
 * and Journal) defaults to the year's first day and never passes "as of".
 */
final readonly class StatementDate
{
    private function __construct(
        public FiscalYearFilter $fy,
        public CarbonImmutable $asOf,
        public CarbonImmutable $from,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(Company $company, array $query, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $asked = self::day($query['asof'] ?? null);

        // A date alone picks its own fiscal year.
        $fyQuery = $query['fy'] ?? ($asked !== null ? FiscalYears::startOf($company, $asked)->year : null);
        $fy = FiscalYearFilter::for($company, $fyQuery, $today);
        $end = $fy->period->to;
        $default = $fy->isCurrent() ? $today : $end;

        $asOf = $asked === null || $asked->lt($fy->start) || $asked->gt($end) ? $default : $asked;

        $from = self::day($query['from'] ?? null);
        $from = $from === null || $from->lt($fy->start) || $from->gt($asOf) ? $fy->start : $from;

        return new self($fy, $asOf, $from);
    }

    public function fyStart(): CarbonImmutable
    {
        return $this->fy->start;
    }

    /** True when the period starts on the year's first day. */
    public function fromYearStart(): bool
    {
        return $this->from->equalTo($this->fy->start);
    }

    /** @return array{asOf: string, from: string, fy: array<string, mixed>, min: string, max: string} */
    public function props(): array
    {
        return [
            'asOf' => $this->asOf->toDateString(),
            'from' => $this->from->toDateString(),
            'fy' => $this->fy->props(),
            'min' => $this->fy->start->toDateString(),
            'max' => $this->fy->period->to->toDateString(),
        ];
    }

    /** @return array{fy: int, asof: string, from?: string} */
    public function query(): array
    {
        return [
            'fy' => $this->fy->start->year,
            'asof' => $this->asOf->toDateString(),
            ...($this->fromYearStart() ? [] : ['from' => $this->from->toDateString()]),
        ];
    }

    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $day instanceof CarbonImmutable ? $day : null;
    }
}
