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
}
