<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;

/**
 * Where a workspace stands (D80, D81): its status, the plan whose limits apply, the
 * trial and grace dates, and whether it has turned read-only.
 *
 * Statuses: `unmanaged` (no plan was ever set: internal or legacy; unlimited, never
 * read-only), `trial` (the no-card trial), `active` (paying, or on a Stripe trial),
 * `past_due` (a payment failed), `canceled` (cancelled, paid until the period ends),
 * `expired` (the trial or the subscription ended unpaid).
 */
final readonly class BillingState
{
    public function __construct(
        public string $status,
        public ?Plan $plan,
        public ?Subscription $subscription,
        public ?CarbonImmutable $trialEndsAt,
        /** When an expired or past-due workspace turns read-only. */
        public ?CarbonImmutable $graceEndsAt,
        public string $cycle,
        public string $currency,
    ) {}

    public function hasSubscription(): bool
    {
        return $this->subscription !== null;
    }

    public function isManaged(): bool
    {
        return $this->status !== 'unmanaged';
    }

    /** Spec §20 / D81: data stays viewable; only changes stop, after the grace period. */
    public function isReadOnly(): bool
    {
        return in_array($this->status, ['past_due', 'expired'], true)
            && $this->graceEndsAt !== null
            && $this->graceEndsAt->isPast();
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->status !== 'trial' || $this->trialEndsAt === null) {
            return null;
        }

        return max(0, (int) ceil(CarbonImmutable::now()->diffInHours($this->trialEndsAt, false) / 24));
    }
}
