<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use Illuminate\Http\Request;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier's webhook endpoint, bound in its place (B4, D116). Cashier checks the Stripe
 * signature only when `STRIPE_WEBHOOK_SECRET` is set, and otherwise accepts anything;
 * here an unset secret refuses every webhook (403). Locally, `stripe listen` prints the
 * secret to use.
 *
 * Its subscription handlers are also what Billing reuses to sync straight from Stripe on
 * the return from Checkout and on "Refresh from Stripe" (B2), so both paths write the
 * same rows.
 */
final class StripeWebhookController extends WebhookController
{
    public function handleWebhook(Request $request)
    {
        abort_unless(filled(config('cashier.webhook.secret')), 403, 'Stripe webhooks are off until STRIPE_WEBHOOK_SECRET is set.');

        return parent::handleWebhook($request);
    }

    /**
     * Write a Stripe subscription object to the local rows as its webhook would, then let
     * SyncWorkspaceBilling copy plan, cycle and renewal onto the workspace. Idempotent.
     *
     * @param  array<string, mixed>  $object  a Stripe subscription, as an array
     */
    public function syncSubscription(array $object, bool $isNew): Response
    {
        $payload = [
            'type' => $isNew ? 'customer.subscription.created' : 'customer.subscription.updated',
            'data' => ['object' => $object],
        ];

        $response = $isNew ? $this->handleCustomerSubscriptionCreated($payload) : $this->handleCustomerSubscriptionUpdated($payload);

        // A brand-new row still gets the fields only the "updated" handler copies (status, ends at).
        if ($isNew) {
            $this->handleCustomerSubscriptionUpdated($payload);
        }

        WebhookHandled::dispatch($payload);

        return $response ?? $this->successMethod();
    }
}
