<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Workspace;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payment provider behind Billing (spec §20, D80). Stripe (through Cashier) is the
 * only one built; Razorpay is "a later option" and would be a second implementation
 * chosen by `billing.gateway` [VERIFY].
 */
interface PaymentGateway
{
    /** A hosted checkout for a new subscription; returns the URL to send the browser to. */
    public function checkout(Workspace $workspace, Plan $plan, string $cycle, string $currency, string $successUrl, string $cancelUrl): string;

    /** Moves an existing subscription to another plan or cycle (prorated, invoiced now); resumes a cancelled one still in its paid period. */
    public function swap(Workspace $workspace, Plan $plan, string $cycle, string $currency): void;

    /** The hosted portal for cards, invoices and cancelling; returns its URL. */
    public function portal(Workspace $workspace, string $returnUrl): string;

    /** @return list<array{id: string, number: string, date: string, period: string, plan: string, amount: string, status: string}> newest first */
    public function invoices(Workspace $workspace): array;

    public function downloadInvoice(Workspace $workspace, string $invoiceId): Response;

    /**
     * Back from Checkout (B2, D117): read the Checkout Session from Stripe and write its
     * subscription and card locally, as the webhook would. False if it isn't this workspace's.
     */
    public function syncCheckout(Workspace $workspace, string $sessionId): bool;

    /** "Refresh from Stripe" (B2) and the duplicate guard (B3): sync the customer's subscriptions; true if one is live. */
    public function syncFromStripe(Workspace $workspace): bool;

    /** Ends the subscription now, with no further charge (workspace deletion, D98). No subscription: nothing to do. */
    public function cancelNow(Workspace $workspace): void;
}
