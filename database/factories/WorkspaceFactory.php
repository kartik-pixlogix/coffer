<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'owner_id' => User::factory(),
            'trial_ends_at' => now()->addDays(Workspace::TRIAL_DAYS),
        ];
    }
}
