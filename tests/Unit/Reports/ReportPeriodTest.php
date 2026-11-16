<?php

declare(strict_types=1);

use App\Ledger\OpeningBalance;
use App\Services\Reports\ProfitAndLossReport;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StatementDate;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->books = singleBooks(openingPaise: 1_00_000_00);
    app(OpeningBalance::class)->post($this->books->company, CarbonImmutable::create(2025, 4, 1));
    $this->today = CarbonImmutable::create(2026, 11, 15);
});

$span = fn (ReportPeriod $p) => [$p->period->from?->toDateString(), $p->period->to->toDateString(), $p->previous->from?->toDateString(), $p->previous->to->toDateString()];

test('FY to date by default, against the same span a year earlier', function () use ($span) {
    $p = ReportPeriod::fromQuery($this->books->company, [], $this->today);

    expect($p->preset)->toBe('fy')
        ->and($span($p))->toBe(['2026-04-01', '2026-11-15', '2025-04-01', '2025-11-15'])
        ->and($p->comparedTo())->toBe('the same span of FY 2025-26')
        ->and($p->monthCount())->toBe(8)
        ->and(ReportPeriod::rangeLabel($p->period))->toBe('1 Apr – 15 Nov 2026');
});

test('a past fiscal year shows in full', function () use ($span) {
    $p = ReportPeriod::fromQuery($this->books->company, ['fy' => '2025'], $this->today);

    expect($span($p))->toBe(['2025-04-01', '2026-03-31', '2024-04-01', '2025-03-31'])
        ->and(ReportPeriod::rangeLabel($p->period))->toBe('1 Apr 2025 – 31 Mar 2026');
});

test('quarters compare with the quarter before; the current one stops at today', function () use ($span) {
    $q2 = ReportPeriod::fromQuery($this->books->company, ['period' => 'q2'], $this->today);
    $q3 = ReportPeriod::fromQuery($this->books->company, ['period' => 'q3'], $this->today);
    $q1 = ReportPeriod::fromQuery($this->books->company, ['period' => 'q1'], $this->today);

    expect($span($q2))->toBe(['2026-07-01', '2026-09-30', '2026-04-01', '2026-06-30'])
        ->and($q2->comparedTo())->toBe('Q1')
        ->and($span($q3))->toBe(['2026-10-01', '2026-11-15', '2026-07-01', '2026-08-15'])
        ->and($q1->comparedTo())->toBe('Q4 of FY 2025-26');
});

test('a quarter that hasn’t started falls back to the year to date', function () {
    expect(ReportPeriod::fromQuery($this->books->company, ['period' => 'q4'], $this->today)->preset)->toBe('fy');
});

test('a month compares with the month before', function () use ($span) {
    $oct = ReportPeriod::fromQuery($this->books->company, ['period' => 'month', 'month' => '2026-10'], $this->today);
    $nov = ReportPeriod::fromQuery($this->books->company, ['period' => 'month', 'month' => '2026-11'], $this->today);

    expect($span($oct))->toBe(['2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'])
        ->and($oct->comparedTo())->toBe('September')
        ->and($span($nov))->toBe(['2026-11-01', '2026-11-15', '2026-10-01', '2026-10-15'])
        ->and(ReportPeriod::fromQuery($this->books->company, ['period' => 'month', 'month' => '2027-01'], $this->today)->preset)->toBe('fy');
});

test('a custom range compares with the same number of days before it', function () use ($span) {
    $p = ReportPeriod::fromQuery($this->books->company, ['period' => 'custom', 'from' => '2026-05-11', 'to' => '2026-05-20'], $this->today);

    expect($span($p))->toBe(['2026-05-11', '2026-05-20', '2026-05-01', '2026-05-10'])
        ->and($p->comparedTo())->toBe('the 10 days before')
        ->and($p->query())->toBe(['period' => 'custom', 'from' => '2026-05-11', 'to' => '2026-05-20']);

    foreach ([['from' => '2026-05-20', 'to' => '2026-05-11'], ['from' => '2026-02-30', 'to' => '2026-05-11'], ['from' => 'x', 'to' => 'y']] as $bad) {
        expect(ReportPeriod::fromQuery($this->books->company, ['period' => 'custom', ...$bad], $this->today)->preset)->toBe('fy');
    }
});

test('statement dates stay inside the chosen year', function () {
    $company = $this->books->company;

    expect(StatementDate::fromQuery($company, [], $this->today)->asOf->toDateString())->toBe('2026-11-15')
        ->and(StatementDate::fromQuery($company, ['fy' => 2025], $this->today)->asOf->toDateString())->toBe('2026-03-31')
        ->and(StatementDate::fromQuery($company, ['asof' => '2025-09-30'], $this->today)->fy->start->year)->toBe(2025)
        ->and(StatementDate::fromQuery($company, ['fy' => 2026, 'asof' => '2025-09-30'], $this->today)->asOf->toDateString())->toBe('2026-11-15');
});

test('ratios are basis points rounded half away from zero', function () {
    expect(ProfitAndLossReport::ratio(1_842_350, 6_210_000))->toBe(2967)
        ->and(ProfitAndLossReport::ratio(-5, 1000))->toBe(-50)
        ->and(ProfitAndLossReport::ratio(1, 0))->toBeNull()
        ->and(ProfitAndLossReport::divide(7, 2))->toBe(4)
        ->and(ProfitAndLossReport::divide(-7, 2))->toBe(-4);
});
