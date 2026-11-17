<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Plan;
use App\Models\Workspace;
use App\Notifications\Events\PaymentFailed;
use App\Notifications\Events\TrialEnding;
use App\Services\Billing\WorkspaceBilling;
use App\Services\Notifications\Audience;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Keeps the workspace in step with Stripe (D80). Cashier's webhook controller verifies the
 * signature and syncs the subscription rows; after that, this copies the plan, cycle,
 * currency and renewal date onto the workspace and tracks when payments started failing
 * (for the read-only grace period, D81). Events Cashier doesn't handle — a failed or paid
 * invoice, a trial about to end — are acted on as they arrive.
 */
final class SyncWorkspaceBilling
{
    public function __construct(
        private readonly WorkspaceBilling $billing,
        private readonly Audience $audience,
    ) {}

    public function handle(WebhookReceived|WebhookHandled $event): void
    {
        /** @var array{type?: string, data?: array{object?: array<string, mixed>}} $payload */
        $payload = $event->payload;
        $type = $payload['type'] ?? '';
        $object = $payload['data']['object'] ?? [];
        $customer = $object['customer'] ?? null;
        $workspace = is_string($customer) ? Cashier::findBillable($customer) : null;

        if (! $workspace instanceof Workspace) {
            return;
        }

        if ($event instanceof WebhookHandled && str_starts_with($type, 'customer.subscription.')) {
            $this->subscription($workspace, $object, $type);
        }

        if ($event instanceof WebhookReceived) {
            match ($type) {
                'invoice.payment_failed' => $this->paymentFailed($workspace, $object),
                'invoice.paid', 'invoice.payment_succeeded' => $workspace->forceFill(['past_due_since' => null])->save(),
                'customer.subscription.trial_will_end' => $this->trialEnding($workspace, $object),
                default => null,
            };
        }

        $this->billing->forget($workspace);
    }

    /** @param array<string, mixed> $object */
    private function subscription(Workspace $workspace, array $object, string $type): void
    {
        /** @var array<string, mixed> $item */
        $item = $object['items']['data'][0] ?? [];
        $price = $item['price']['id'] ?? null;
        $info = WorkspaceBilling::priceInfo(is_string($price) ? $price : null);
        $status = (string) ($object['status'] ?? '');
        $periodEnd = $item['current_period_end'] ?? $object['current_period_end'] ?? null;

        $changes = [
            'renews_at' => is_int($periodEnd) && $type !== 'customer.subscription.deleted' ? CarbonImmutable::createFromTimestamp($periodEnd, (string) config('app.timezone')) : null,
            'past_due_since' => in_array($status, ['past_due', 'unpaid'], true) ? ($workspace->past_due_since ?? now()) : null,
        ];

        if ($info !== null) {
            $changes += [
                'plan_id' => Plan::query()->where('key', $info['plan'])->value('id') ?? $workspace->plan_id,
                'billing_cycle' => $info['cycle'],
                'billing_currency' => $info['currency'],
            ];
        }

        $workspace->forceFill($changes)->save();
    }

    /** @param array<string, mixed> $object */
    private function paymentFailed(Workspace $workspace, array $object): void
    {
        $workspace->forceFill(['past_due_since' => $workspace->past_due_since ?? now()])->save();

        // Stripe retries a failed invoice several times; people hear once a day per invoice.
        if (! Cache::add('billing:payment_failed:'.($object['id'] ?? 'unknown'), true, now()->addDay())) {
            return;
        }

        $raw = (int) ($object['amount_due'] ?? 0);
        $amount = strtolower((string) ($object['currency'] ?? 'inr')) === 'inr' ? Money::format($raw) : '$'.number_format($raw / 100, 2);

        Notification::send($this->audience->billing($workspace), PaymentFailed::for($workspace, $amount, (int) config('billing.grace_days')));
    }

    /** @param array<string, mixed> $object */
    private function trialEnding(Workspace $workspace, array $object): void
    {
        $end = $object['trial_end'] ?? null;
        $days = is_int($end) ? max(1, (int) ceil(CarbonImmutable::now()->diffInHours(CarbonImmutable::createFromTimestamp($end), false) / 24)) : 3;

        Notification::send($this->audience->billing($workspace), TrialEnding::for($workspace, $days));
    }
}
