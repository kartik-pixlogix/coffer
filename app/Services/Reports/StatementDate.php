<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Services\Finance\FiscalYearFilter;
use Carbon\CarbonImmutable;

/**
 * The "As of" date and fiscal year on Trial balance and Balance sheet (Statements.dc.html):
 * `?fy=2026&asof=2026-11-15`. The date stays inside the chosen year; by default it's
 * today in the current year and the last day of a past one.
 */
final readonly class StatementDate
{
    private function __construct(
        public FiscalYearFilter $fy,
        public CarbonImmutable $asOf,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromQuery(Company $company, array $query, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::today();
        $asked = is_string($query['asof'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['asof']) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $query['asof'])
            : null;
        $asked = $asked instanceof CarbonImmutable ? $asked : null;

        // A date alone picks its own fiscal year.
        $fyQuery = $query['fy'] ?? ($asked !== null ? FiscalYears::startOf($company, $asked)->year : null);
        $fy = FiscalYearFilter::for($company, $fyQuery, $today);
        $end = $fy->period->to;
        $default = $fy->isCurrent() ? $today : $end;

        $asOf = $asked === null || $asked->lt($fy->start) || $asked->gt($end) ? $default : $asked;

        return new self($fy, $asOf);
    }

    public function fyStart(): CarbonImmutable
    {
        return $this->fy->start;
    }

    /** @return array{asOf: string, fy: array<string, mixed>, min: string, max: string} */
    public function props(): array
    {
        return [
            'asOf' => $this->asOf->toDateString(),
            'fy' => $this->fy->props(),
            'min' => $this->fy->start->toDateString(),
            'max' => $this->fy->period->to->toDateString(),
        ];
    }

    /** @return array{fy: int, asof: string} */
    public function query(): array
    {
        return ['fy' => $this->fy->start->year, 'asof' => $this->asOf->toDateString()];
    }
}
