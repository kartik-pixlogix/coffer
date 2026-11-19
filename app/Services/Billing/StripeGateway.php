<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Http\Controllers\Billing\StripeWebhookController;
use App\Models\Plan;
use App\Models\Workspace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Invoice;
use Stripe\PaymentMethod;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe through Cashier (spec §20 "Checkout + Billing Portal", D80). The workspace is the
 * customer. A workspace still on its no-card trial keeps the days it has left: Checkout
 * starts the subscription with the same trial end, and Stripe charges when it's over.
 */
final class StripeGateway implements PaymentGateway
{
    /** Subscription states that are a live plan (a second Checkout would double it, B3). */
    public const array LIVE = ['active', 'trialing', 'past_due', 'unpaid'];

    public function checkout(Workspace $workspace, Plan $plan, string $cycle, string $currency, string $successUrl, string $cancelUrl): string
    {
        $price = $this->price($plan, $cycle, $currency);
        $this->assertPriceMatches($plan, $cycle, $currency, $price);
        $builder = $workspace->newSubscription('default', $price);

        // B7 (D117): Stripe gets the same whole number of days Billing shows (BillingState::
        // trialDaysLeft rounds up), so Checkout's "N days free" and the page agree.
        $days = app(WorkspaceBilling::class)->for($workspace)->trialDaysLeft();

        if ($workspace->onGenericTrial() && $days !== null && $days > 0) {
            $builder->trialUntil(now()->addDays($days));
        }

        $checkout = $builder->checkout([
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $workspace->id,
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => true],
            'customer_update' => ['name' => 'auto', 'address' => 'auto'],
            'subscription_data' => ['metadata' => ['workspace_id' => (string) $workspace->id, 'plan' => $plan->key]],
        ]);

        return (string) $checkout->asStripeCheckoutSession()->url;
    }

    public function swap(Workspace $workspace, Plan $plan, string $cycle, string $currency): void
    {
        $subscription = $workspace->subscription('default');

        if ($subscription === null) {
            throw ValidationException::withMessages(['plan' => 'There’s no subscription to change yet. Choose a plan to start one.']);
        }

        $price = $this->price($plan, $cycle, $currency);
        $this->assertPriceMatches($plan, $cycle, $currency, $price);

        // A cancelled plan still inside its paid period is switched back on, never doubled up.
        if ($subscription->onGracePeriod()) {
            $subscription->resume();
        }

        if ($subscription->stripe_price !== $price) {
            $subscription->swapAndInvoice($price);
        }
    }

    public function syncCheckout(Workspace $workspace, string $sessionId): bool
    {
        $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription', 'subscription.default_payment_method']]);
        $mine = ($workspace->stripe_id !== null && $session->customer === $workspace->stripe_id)
            || $session->client_reference_id === (string) $workspace->id;

        if (! $mine) {
            Log::warning('Billing: a Checkout Session for another customer came back', ['workspace' => $workspace->id, 'session' => $sessionId]);

            return false;
        }

        if ($workspace->stripe_id === null && is_string($session->customer)) {
            $workspace->forceFill(['stripe_id' => $session->customer])->save();
        }

        $subscription = $session->subscription;

        if (! $subscription instanceof StripeSubscription) {
            return false;
        }

        $this->write($workspace, $subscription);

        return true;
    }

    public function syncFromStripe(Workspace $workspace): bool
    {
        if (! $workspace->hasStripeId()) {
            return false;
        }

        $list = Cashier::stripe()->subscriptions->all([
            'customer' => $workspace->stripe_id,
            'status' => 'all',
            'limit' => 10,
            'expand' => ['data.default_payment_method'],
        ]);
        $live = false;

        foreach ($list->data as $subscription) {
            $this->write($workspace, $subscription);
            $live = $live || in_array($subscription->status, self::LIVE, true);
        }

        return $live;
    }

    /** One Stripe subscription onto the local rows (the webhook's own handlers) and its card onto the workspace. */
    private function write(Workspace $workspace, StripeSubscription $subscription): void
    {
        $isNew = ! $workspace->subscriptions()->where('stripe_id', $subscription->id)->exists();
        app(StripeWebhookController::class)->syncSubscription($subscription->toArray(), $isNew);

        $method = $subscription->default_payment_method;

        if ($method instanceof PaymentMethod && $method->card !== null) {
            $workspace->forceFill(['pm_type' => $method->card->brand, 'pm_last_four' => $method->card->last4])->save();
        }

        app(WorkspaceBilling::class)->forget($workspace);
    }

    /**
     * B1 safeguard (D117): the Stripe price behind a plan must charge what Coffer shows.
     * A mismatch is logged for the platform team and refused, never sold.
     */
    private function assertPriceMatches(Plan $plan, string $cycle, string $currency, string $priceId): void
    {
        $price = Cashier::stripe()->prices->retrieve($priceId);
        $expected = $plan->price($cycle, $currency);

        if ($price->unit_amount === $expected && strtolower((string) $price->currency) === strtolower($currency)) {
            return;
        }

        Log::error('Billing: Stripe price does not match the plan', [
            'plan' => $plan->key, 'cycle' => $cycle, 'currency' => $currency, 'price' => $priceId,
            'expected' => $expected, 'stripe_amount' => $price->unit_amount, 'stripe_currency' => $price->currency,
        ]);

        throw ValidationException::withMessages(['plan' => "{$plan->name} can’t be bought right now: its Stripe price doesn’t match the price shown here. We’ve logged it for the Coffer team; nothing was charged."]);
    }

    public function cancelNow(Workspace $workspace): void
    {
        $subscription = $workspace->subscription('default');

        if ($subscription !== null && ! $subscription->ended()) {
            $subscription->cancelNow();
        }
    }

    public function portal(Workspace $workspace, string $returnUrl): string
    {
        $workspace->createOrGetStripeCustomer();

        return $workspace->billingPortalUrl($returnUrl);
    }

    public function invoices(Workspace $workspace): array
    {
        if (! $workspace->hasStripeId()) {
            return [];
        }

        return array_values($workspace->invoices()->map(fn (Invoice $invoice) => $this->row($invoice))->all());
    }

    public function downloadInvoice(Workspace $workspace, string $invoiceId): Response
    {
        return $workspace->downloadInvoice($invoiceId, [
            'vendor' => 'Coffer',
            'product' => 'Coffer subscription',
            'street' => $workspace->billing_name ?? $workspace->name,
            'vat' => $workspace->billing_gstin,
        ]);
    }

    private function price(Plan $plan, string $cycle, string $currency): string
    {
        return $plan->stripePrice($cycle, $currency) ?? throw ValidationException::withMessages([
            'plan' => "{$plan->name} isn’t set up for {$cycle} payments in ".strtoupper($currency).' yet.',
        ]);
    }

    /** @return array{id: string, number: string, date: string, period: string, plan: string, amount: string, status: string} */
    private function row(Invoice $invoice): array
    {
        $stripe = $invoice->asStripeInvoice();
        $line = $stripe->lines->data[0] ?? null;
        $start = $line?->period->start ?? $stripe->period_start;
        $end = $line?->period->end ?? $stripe->period_end;
        $info = WorkspaceBilling::priceInfo($this->linePrice($line));
        $plan = $info !== null ? ucfirst($info['plan']).' · '.$info['cycle'] : (string) ($line->description ?? 'Coffer');

        return [
            'id' => (string) $stripe->id,
            'number' => (string) ($stripe->number ?? $stripe->id),
            'date' => $invoice->date('Asia/Kolkata')->format('j M Y'),
            'period' => self::period(CarbonImmutable::createFromTimestamp($start, 'Asia/Kolkata'), CarbonImmutable::createFromTimestamp($end, 'Asia/Kolkata')->subSecond()),
            'plan' => $plan,
            'amount' => strtolower((string) $stripe->currency) === 'inr' ? Money::format($invoice->rawTotal()) : '$'.number_format($invoice->rawTotal() / 100, 2),
            'status' => (string) $stripe->status,
        ];
    }

    private function linePrice(mixed $line): ?string
    {
        if (! is_object($line)) {
            return null;
        }

        // Newer API versions name it under pricing; older ones put the Price on the line.
        $id = $line->pricing->price_details->price ?? $line->price->id ?? null;

        return is_string($id) ? $id : null;
    }

    /** "1 – 30 Nov 2026", "18 Jul – 17 Aug 2026" (Billing.dc.html). */
    public static function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return match (true) {
            $from->isSameMonth($to) => $from->format('j').' – '.$to->format('j M Y'),
            $from->isSameYear($to) => $from->format('j M').' – '.$to->format('j M Y'),
            default => $from->format('j M Y').' – '.$to->format('j M Y'),
        };
    }
}
