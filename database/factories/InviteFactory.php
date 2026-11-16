<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyRole;
use App\Enums\InviteKind;
use App\Models\Company;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Read a factory invite's plain token back with `$invite->token()`.
 *
 * @extends Factory<Invite>
 */
class InviteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'kind' => InviteKind::Email,
            'email' => fake()->unique()->safeEmail(),
            'role' => CompanyRole::Member,
            'invited_by' => User::factory(),
            ...Invite::tokenAttributes(Invite::newToken()),
            'expires_at' => now()->addDays(Invite::DEFAULT_EXPIRY_DAYS),
            'last_sent_at' => now(),
        ];
    }

    public function link(): static
    {
        return $this->state(fn () => ['kind' => InviteKind::Link, 'email' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
