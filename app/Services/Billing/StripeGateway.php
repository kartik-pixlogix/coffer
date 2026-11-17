<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Workspace;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Invoice;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe through Cashier (spec §20 "Checkout + Billing Portal", D80). The workspace is the
 * customer. A workspace still on its no-card trial keeps the days it has left: Checkout
 * starts the subscription with the same trial end, and Stripe charges when it's over.
 */
final class StripeGateway implements PaymentGateway
{
    public function checkout(Workspace $workspace, Plan $plan, string $cycle, string $currency, string $successUrl, string $cancelUrl): string
    {
        $builder = $workspace->newSubscription('default', $this->price($plan, $cycle, $currency));

        if ($workspace->onGenericTrial() && $workspace->trial_ends_at !== null) {
            $builder->trialUntil($workspace->trial_ends_at);
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

        // A cancelled plan still inside its paid period is switched back on, never doubled up.
        if ($subscription->onGracePeriod()) {
            $subscription->resume();
        }

        if ($subscription->stripe_price !== $price) {
            $subscription->swapAndInvoice($price);
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
