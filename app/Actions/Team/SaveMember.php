<?php

declare(strict_types=1);

namespace App\Actions\Team;

use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Enums\TdsSection;
use App\Models\Member;
use App\Models\User;
use App\Services\Team\MemberRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds or edits a team member (Team.dc.html "Add member", spec §7). A new member starts
 * with their rate from the start of the fiscal year; a changed rate on edit is a new
 * history row from the chosen date, so salaries already run don't move (D37).
 */
final class SaveMember
{
    public function __construct(private readonly MemberRates $rates) {}

    /**
     * @param  array{name: string, designation: string|null, partner_id: int, user_id: int|null, cost_type: CostType, rate_paise: int, effective_from: CarbonImmutable|null, tds_section?: TdsSection|null, tds_monthly_paise?: int}  $data
     */
    public function handle(User $actor, ?Member $member, array $data): Member
    {
        return DB::transaction(function () use ($actor, $member, $data): Member {
            $details = [
                'name' => $data['name'],
                'designation' => $data['designation'],
                'partner_id' => $data['partner_id'],
                'user_id' => $data['user_id'],
                // TDS on their pay (D161); salary runs hold it back from then on.
                'tds_section' => $data['tds_section'] ?? null,
                'tds_monthly_paise' => $data['tds_section'] ?? null ? ($data['tds_monthly_paise'] ?? 0) : 0,
            ];

            if ($member === null) {
                $member = Member::query()->create($details + [
                    'cost_type' => $data['cost_type'],
                    'rate_paise' => $data['rate_paise'],
                    'status' => MemberStatus::Active,
                ]);

                activity('team')->performedOn($member)->causedBy($actor)->log('added a team member');

                return $member;
            }

            $member->update($details);

            $current = $this->rates->on($member, $data['effective_from'] ?? CarbonImmutable::today());

            if ($current->cost_type !== $data['cost_type'] || $current->rate_paise !== $data['rate_paise']) {
                $from = $data['effective_from'] ?? CarbonImmutable::today();
                $this->rates->change($member, $data['cost_type'], $data['rate_paise'], $from, $actor);

                activity('team')->performedOn($member)->causedBy($actor)
                    ->withProperties(['cost_type' => $data['cost_type']->value, 'rate_paise' => $data['rate_paise'], 'from' => $from->toDateString()])
                    ->log('changed a rate');
            }

            activity('team')->performedOn($member)->causedBy($actor)->log('updated a team member');

            return $member;
        });
    }
}
