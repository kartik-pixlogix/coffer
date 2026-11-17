<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;
use WeakMap;

/**
 * Works out a workspace's BillingState (D80, D81) from its Cashier subscription and its
 * no-card trial. Remembered per workspace model instance, so a request (which loads its
 * own) asks once, and a later request never sees a stale answer.
 */
final class WorkspaceBilling
{
    /** @var WeakMap<Workspace, BillingState> */
    private WeakMap $states;

    public function __construct()
    {
        $this->states = new WeakMap;
    }

    public function for(Workspace $workspace): BillingState
    {
        return $this->states[$workspace] ??= $this->resolve($workspace);
    }

    public function forget(Workspace $workspace): void
    {
        unset($this->states[$workspace]);
    }

    /**
     * The plan a Stripe price id belongs to, with its cycle and currency.
     *
     * @return array{plan: string, cycle: string, currency: string}|null
     */
    public static function priceInfo(?string $price): ?array
    {
        if ($price === null || $price === '') {
            return null;
        }

        /** @var array<string, array<string, array<string, string|null>>> $prices */
        $prices = config('billing.stripe.prices', []);

        foreach ($prices as $plan => $cycles) {
            foreach ($cycles as $cycle => $currencies) {
                foreach ($currencies as $currency => $id) {
                    if ($id === $price) {
                        return ['plan' => $plan, 'cycle' => $cycle, 'currency' => $currency];
                    }
                }
            }
        }

        return null;
    }

    private function resolve(Workspace $workspace): BillingState
    {
        $grace = (int) config('billing.grace_days');
        /** @var Subscription|null $subscription */
        $subscription = $workspace->subscriptions()->where('type', 'default')->latest('id')->first();
        $info = self::priceInfo($subscription?->stripe_price);
        $plan = ($info !== null ? Plan::query()->where('key', $info['plan'])->first() : null) ?? $workspace->plan;
        $cycle = $info['cycle'] ?? $workspace->billing_cycle ?? 'monthly';
        $currency = $info['currency'] ?? $workspace->billing_currency ?? (string) config('billing.default_currency');
        $trialEnds = $workspace->trial_ends_at?->toImmutable();
        $onTrial = $trialEnds !== null && $trialEnds->isFuture();

        if ($subscription !== null && ! $subscription->ended()) {
            $status = match (true) {
                $subscription->pastDue(), $subscription->stripe_status === 'unpaid' => 'past_due',
                $subscription->incomplete() => $onTrial ? 'trial' : 'past_due',
                $subscription->onGracePeriod() => 'canceled',
                default => 'active',
            };
            $since = $workspace->past_due_since?->toImmutable() ?? CarbonImmutable::instance($subscription->updated_at ?? now());

            return new BillingState($status, $plan, $subscription, $trialEnds, $status === 'past_due' ? $since->addDays($grace) : null, $cycle, $currency);
        }

        if ($workspace->plan_id === null && $subscription === null) {
            return new BillingState('unmanaged', null, null, $trialEnds, null, $cycle, $currency);
        }

        if ($onTrial) {
            return new BillingState('trial', $plan, null, $trialEnds, null, $cycle, $currency);
        }

        // The trial or the subscription ended unpaid: soft limits still apply, read-only after grace.
        $endedAt = $subscription?->ends_at !== null ? CarbonImmutable::instance($subscription->ends_at) : $trialEnds;

        return new BillingState('expired', $plan, null, $trialEnds, ($endedAt ?? CarbonImmutable::now())->addDays($grace), $cycle, $currency);
    }
}
