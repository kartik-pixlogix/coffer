<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Enums\CostType;
use App\Models\Member;
use App\Models\MemberRate;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What members earn in a month (spec §7, §8.3), the figure the Team cards, the
 * Timesheets summary and the salary run all share (D37):
 *
 * - Hourly: billable hours × the rate in effect on each entry's day.
 * - Fixed monthly: the monthly cost in effect on the month's last active day, for the
 *   full month, whenever the member was active on any day of it. No proration.
 *
 * The rate type on the last active day decides the month: hourly days earlier in a
 * month that ends on a fixed rate are covered by that month's fixed cost.
 */
final class Earnings
{
    public function __construct(private readonly MemberRates $rates) {}

    /**
     * @param  iterable<Member>  $members  with `rates` loaded (loaded here if not)
     * @param  CarbonImmutable|null  $through  only count entries up to this day (month to date)
     * @return array<int, MonthEarnings> member id → the month
     */
    public function forMonth(iterable $members, CarbonImmutable $month, ?CarbonImmutable $through = null): array
    {
        $members = collect($members)->keyBy('id');

        if ($members->isEmpty()) {
            return [];
        }

        $members->each(fn (Member $m) => $m->loadMissing('rates'));

        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();
        $last = $through !== null && $through->lessThan($end) ? $through : $end;

        /** @var Collection<int, Collection<int, object{member_id: int, date: string, billable: int|bool, centi: int|string}>> $days */
        $days = TimeEntry::query()->withoutGlobalScope('company')
            ->whereIn('member_id', $members->keys())
            ->whereBetween('date', [$start->toDateString(), $last->toDateString()])
            ->selectRaw('member_id, date, billable, SUM(hours_centi) as centi')
            ->groupBy('member_id', 'date', 'billable')
            ->toBase()
            ->get()
            ->groupBy('member_id');

        $out = [];

        foreach ($members as $id => $member) {
            $out[(int) $id] = $this->month($member, $start, $end, $days->get($id, collect()));
        }

        return $out;
    }

    public function forMember(Member $member, CarbonImmutable $month, ?CarbonImmutable $through = null): MonthEarnings
    {
        return $this->forMonth([$member], $month, $through)[$member->id];
    }

    /** @param Collection<int, object{member_id: int, date: string, billable: int|bool, centi: int|string}> $days */
    private function month(Member $member, CarbonImmutable $start, CarbonImmutable $end, Collection $days): MonthEarnings
    {
        $hours = (int) $days->sum(fn (object $d) => (int) $d->centi);
        $billable = $days->filter(fn (object $d) => (bool) $d->billable);
        $billableCenti = (int) $billable->sum(fn (object $d) => (int) $d->centi);

        $gone = $member->deactivated_on;
        $active = ($gone === null || $gone->greaterThanOrEqualTo($start))
            && $member->rates->first()?->effective_from->lessThanOrEqualTo($end) === true;
        $lastActive = $gone !== null && $gone->lessThan($end) ? $gone : $end;
        $closing = $this->rates->on($member, $lastActive);

        if ($closing->cost_type === CostType::FixedMonthly) {
            return new MonthEarnings($hours, $billableCenti, $active ? $closing->rate_paise : 0, true, $active);
        }

        // Hourly: group billable hours by the rate row in effect on each day.
        $byRate = [];

        foreach ($billable as $day) {
            $rate = $this->rates->on($member, CarbonImmutable::parse($day->date));

            if ($rate->cost_type === CostType::Hourly) {
                $byRate[$rate->id] = [$rate, ($byRate[$rate->id][1] ?? 0) + (int) $day->centi];
            }
        }

        $amount = 0;

        foreach ($byRate as [$rate, $centi]) {
            /** @var MemberRate $rate */
            $amount += self::hoursTimesRate($centi, $rate->rate_paise);
        }

        return new MonthEarnings($hours, $billableCenti, $amount, false, $active);
    }

    /** Hundredths of an hour × paise per hour, rounded half up to the paisa. */
    public static function hoursTimesRate(int $centi, int $ratePaise): int
    {
        return intdiv($centi * $ratePaise + 50, 100);
    }
}
