<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'role' => CompanyRole::Partner,
            'colour' => fake()->numberBetween(1, Partner::COLOURS),
            'equity_bps' => 5000,
        ];
    }
}
