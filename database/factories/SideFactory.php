<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Side;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Side>
 */
class SideFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory()->jv(),
            'name' => fake()->company(),
            'equity_bps' => 5000,
        ];
    }
}
