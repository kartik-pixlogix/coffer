<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

/**
 * A new paying tenant for a sign-up (spec §18): owned by the user, on a 14-day trial
 * with no card, with the trial plan's limits (D80). Its first company is made in the
 * create-company wizard.
 */
final class CreateWorkspace
{
    public function handle(User $owner, string $name): Workspace
    {
        return Workspace::query()->create([
            'name' => $name,
            'owner_id' => $owner->id,
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days', Workspace::TRIAL_DAYS)),
            'plan_id' => Plan::query()->where('key', config('billing.trial_plan'))->value('id'),
            'billing_cycle' => 'monthly',
            'billing_currency' => config('billing.default_currency'),
            'billing_email' => $owner->email,
        ]);
    }
}
