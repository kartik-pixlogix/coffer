<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'company_id' => fn (array $attributes): int => Partner::query()->withoutGlobalScopes()->whereKey($attributes['partner_id'])->firstOrFail()->company_id,
            'name' => fake()->name(),
            'designation' => 'Developer',
            'cost_type' => CostType::Hourly,
            'rate_paise' => 500_00,
            'status' => MemberStatus::Active,
        ];
    }
}
