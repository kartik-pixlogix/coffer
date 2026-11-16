<?php

declare(strict_types=1);

namespace App\Services\Team;

use App\Enums\CostType;
use App\Models\Member;
use App\Models\MemberRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * A member's rate history (spec §7). A change takes effect from a chosen date and
 * keeps the rows before it, so salaries already run never change (D37).
 */
final class MemberRates
{
    /**
     * The rate in effect on a day: the latest row starting on or before it. Days before
     * the first row use the first row (hours imported for before someone joined).
     */
    public function on(Member $member, DateTimeInterface $date): MemberRate
    {
        $day = CarbonImmutable::instance($date)->toDateString();
        $rates = $member->rates;
        /** @var MemberRate $rate */
        $rate = $rates->last(fn (MemberRate $r) => $r->effective_from->toDateString() <= $day) ?? $rates->firstOrFail();

        return $rate;
    }

    /** Record a new rate from `$from`, replacing a row that starts the same day. */
    public function change(Member $member, CostType $type, int $ratePaise, CarbonImmutable $from, ?User $by = null): void
    {
        DB::transaction(function () use ($member, $type, $ratePaise, $from, $by): void {
            MemberRate::query()->updateOrCreate(
                ['member_id' => $member->id, 'effective_from' => $from->toDateString()],
                ['cost_type' => $type, 'rate_paise' => $ratePaise, 'created_by' => $by?->id],
            );

            $this->syncCurrent($member);
        });
    }

    /** Point the member's `cost_type` / `rate_paise` at the row in effect today. */
    public function syncCurrent(Member $member): void
    {
        $member->unsetRelation('rates');
        $today = $this->on($member, CarbonImmutable::today());

        $member->forceFill(['cost_type' => $today->cost_type, 'rate_paise' => $today->rate_paise])->saveQuietly();
    }
}
