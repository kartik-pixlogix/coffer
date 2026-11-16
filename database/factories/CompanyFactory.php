<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Companies\SeedCompanyPermissions;
use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Companies come with their four roles and the default permission matrix.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            'short_code' => Str::upper(Str::random(4)),
            'type' => CompanyType::Single,
            'currency' => 'INR',
            'fy_start_month' => 4,
            'attribution_mode' => CompanyType::Single->defaultAttribution(),
            'opening_balance_paise' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Company $company): void {
            app(SeedCompanyPermissions::class)->handle($company);
        });
    }

    public function jv(): static
    {
        return $this->state(fn () => [
            'type' => CompanyType::Jv,
            'attribution_mode' => CompanyType::Jv->defaultAttribution(),
        ]);
    }
}
