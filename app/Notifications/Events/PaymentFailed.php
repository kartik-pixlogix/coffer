<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Workspace;
use App\Notifications\CofferNotification;

/** Stripe couldn't take a payment (D80); workspace-wide. Email can't be turned off. */
final class PaymentFailed extends CofferNotification
{
    public static function for(Workspace $workspace, string $amount, int $graceDays): self
    {
        return new self(
            null,
            "We couldn’t take {$amount} for {$workspace->name}",
            "Update the card in the billing portal. Coffer stays fully usable for {$graceDays} days, then turns read-only until it’s paid. Nothing is deleted.",
            route('billing.index'),
        );
    }

    public function event(): string
    {
        return 'billing.payment_failed';
    }

    public function icon(): string
    {
        return 'credit-card';
    }

    public function tone(): string
    {
        return 'neg';
    }

    public function linkLabel(): string
    {
        return 'Update payment';
    }

    public function mailAction(): string
    {
        return 'Update payment method';
    }
}
