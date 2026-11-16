<?php

declare(strict_types=1);

use App\Enums\CostType;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Team\Earnings;
use App\Services\Team\MemberRates;
use Carbon\CarbonImmutable;

function earner(object $books, CostType $type, int $rate): Member
{
    return Member::factory()->create(['partner_id' => $books->aarav->id, 'cost_type' => $type, 'rate_paise' => $rate]);
}

function hoursOn(Member $member, string $date, int $centi, bool $billable = true): void
{
    TimeEntry::query()->withoutGlobalScope('company')->create([
        'company_id' => $member->company_id,
        'member_id' => $member->id,
        'partner_id' => $member->partner_id,
        'project_id' => Project::query()->withoutGlobalScope('company')->where('company_id', $member->company_id)->value('id'),
        'date' => $date,
        'hours_centi' => $centi,
        'billable' => $billable,
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the rate in effect is the latest row on or before the day, else the first', function () {
    $books = singleBooks();
    $m = earner($books, CostType::Hourly, 500_00);
    $rates = app(MemberRates::class);
    $rates->change($m, CostType::Hourly, 600_00, CarbonImmutable::parse('2026-09-01'));

    $m->load('rates');

    expect($rates->on($m, CarbonImmutable::parse('2026-08-31'))->rate_paise)->toBe(500_00)
        ->and($rates->on($m, CarbonImmutable::parse('2026-09-01'))->rate_paise)->toBe(600_00)
        ->and($rates->on($m, CarbonImmutable::parse('2025-01-01'))->rate_paise)->toBe(500_00);
});

test('hourly earnings use the rate on each entry’s day and only billable hours', function () {
    $books = singleBooks();
    $m = earner($books, CostType::Hourly, 500_00);
    app(MemberRates::class)->change($m, CostType::Hourly, 600_00, CarbonImmutable::parse('2026-10-16'));
    hoursOn($m, '2026-10-15', 1000); // 10 h × ₹500
    hoursOn($m, '2026-10-16', 250);  // 2.5 h × ₹600
    hoursOn($m, '2026-10-17', 400, billable: false);

    $month = app(Earnings::class)->forMember($m->fresh(), CarbonImmutable::parse('2026-10-01'));

    expect($month->hoursCenti)->toBe(1650)
        ->and($month->billableCenti)->toBe(1250)
        ->and($month->amountPaise)->toBe(5_000_00 + 1_500_00)
        ->and($month->fixed)->toBeFalse();
});

test('hours × rate rounds half up to the paisa', function () {
    expect(Earnings::hoursTimesRate(1, 333_33))->toBe(3_33) // 0.01 h × ₹333.33 = ₹3.3333
        ->and(Earnings::hoursTimesRate(5733, 450_00))->toBe(25_798_50);
});

test('fixed members earn the month-end rate for the full month, with no proration', function () {
    $books = singleBooks();
    $m = earner($books, CostType::FixedMonthly, 40_000_00);
    app(MemberRates::class)->change($m, CostType::FixedMonthly, 45_000_00, CarbonImmutable::parse('2026-10-20'));

    $earnings = app(Earnings::class);

    expect($earnings->forMember($m->fresh(), CarbonImmutable::parse('2026-09-01'))->amountPaise)->toBe(40_000_00)
        ->and($earnings->forMember($m->fresh(), CarbonImmutable::parse('2026-10-01'))->amountPaise)->toBe(45_000_00);
});

test('deactivated fixed members are paid for the month they leave and nothing after', function () {
    $books = singleBooks();
    $m = earner($books, CostType::FixedMonthly, 40_000_00);
    $m->update(['deactivated_on' => '2026-10-03']);

    $earnings = app(Earnings::class);
    $october = $earnings->forMember($m->fresh(), CarbonImmutable::parse('2026-10-01'));
    $november = $earnings->forMember($m->fresh(), CarbonImmutable::parse('2026-11-01'));

    expect($october->amountPaise)->toBe(40_000_00)->and($october->active)->toBeTrue()
        ->and($november->amountPaise)->toBe(0)->and($november->active)->toBeFalse();
});

test('a month that ends on a fixed rate is paid as fixed', function () {
    $books = singleBooks();
    $m = earner($books, CostType::Hourly, 500_00);
    app(MemberRates::class)->change($m, CostType::FixedMonthly, 50_000_00, CarbonImmutable::parse('2026-10-15'));
    hoursOn($m, '2026-10-02', 800);

    $october = app(Earnings::class)->forMember($m->fresh(), CarbonImmutable::parse('2026-10-01'));

    expect($october->fixed)->toBeTrue()->and($october->amountPaise)->toBe(50_000_00);
});
