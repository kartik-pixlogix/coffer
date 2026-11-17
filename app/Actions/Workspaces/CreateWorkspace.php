<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

/**
 * A new paying tenant for a sign-up (spec §18): owned by the user, on a 14-day trial
 * with no card, with the trial plan's limits (D80). A sign-up that came from a Pricing
 * CTA (`/register?plan=starter`) trials that plan instead (D83). Its first company is
 * made in the create-company wizard.
 */
final class CreateWorkspace
{
    /** Session key for the plan a Pricing CTA picked, until the sign-up finishes. */
    public const string SIGNUP_PLAN = 'signup.plan';

    public function handle(User $owner, string $name, ?string $planKey = null): Workspace
    {
        return Workspace::query()->create([
            'name' => $name,
            'owner_id' => $owner->id,
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days', Workspace::TRIAL_DAYS)),
            'plan_id' => self::trialPlan($planKey)?->id,
            'billing_cycle' => 'monthly',
            'billing_currency' => config('billing.default_currency'),
            'billing_email' => $owner->email,
        ]);
    }

    /** The picked plan when it can be trialled (not custom-priced), else the default trial plan. */
    public static function trialPlan(?string $planKey): ?Plan
    {
        $picked = $planKey === null ? null : Plan::query()->where('key', $planKey)->where('custom_pricing', false)->first();

        return $picked ?? Plan::query()->where('key', config('billing.trial_plan'))->first();
    }

    /** A `?plan=` value worth remembering for sign-up: a plan that can be trialled. */
    public static function trialPlanKey(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Plan::query()->where('key', $value)->where('custom_pricing', false)->exists() ? $value : null;
    }
}
