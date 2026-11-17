<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Events\PaymentFailed;
use App\Notifications\Events\TrialEnding;
use App\Services\Billing\WorkspaceBilling;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Stripe webhooks (D80): Cashier's controller checks the signature and syncs the
 * subscription; SyncWorkspaceBilling copies plan, cycle and renewal onto the workspace,
 * tracks a failed payment for the grace period and tells billing people. Payloads are
 * signed here with a test secret — no Stripe account involved.
 */
const WEBHOOK_SECRET = 'whsec_test_coffer';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    config([
        'cashier.webhook.secret' => WEBHOOK_SECRET,
        'billing.stripe.prices.growth.monthly.inr' => 'price_growth_m_inr',
        'billing.stripe.prices.business.yearly.inr' => 'price_business_y_inr',
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function hookWorkspace(): Workspace
{
    $owner = User::factory()->create(['name' => 'Aarav Mehta']);
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Vertex Group');
    $company = Company::factory()->create(['workspace_id' => $workspace->id]);
    app(SetCompanyRole::class)->handle($company, $owner, CompanyRole::Admin);
    $workspace->forceFill(['stripe_id' => 'cus_hook'])->save();

    return $workspace->refresh();
}

/** Posts a Stripe event, signed like Stripe does (t=…,v1=HMAC-SHA256 of "t.payload"). */
function stripeEvent(string $type, array $object, ?string $secret = WEBHOOK_SECRET)
{
    $payload = json_encode(['id' => 'evt_'.uniqid(), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]], JSON_THROW_ON_ERROR);
    $time = time();
    $signature = hash_hmac('sha256', "{$time}.{$payload}", (string) $secret);

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

/** @return array<string, mixed> a Stripe subscription object */
function stripeSubscription(string $status, string $price, array $extra = []): array
{
    return $extra + [
        'id' => 'sub_hook', 'object' => 'subscription', 'customer' => 'cus_hook', 'status' => $status,
        'metadata' => ['workspace_id' => '1'], 'trial_end' => null, 'cancel_at_period_end' => false, 'cancel_at' => null, 'ended_at' => null,
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_hook', 'object' => 'subscription_item', 'quantity' => 1,
            'current_period_end' => CarbonImmutable::parse('2026-12-15')->timestamp,
            'price' => ['id' => $price, 'object' => 'price', 'product' => 'prod_coffer', 'recurring' => ['interval' => 'month']],
        ]]],
    ];
}

test('an unsigned or wrongly signed webhook is refused', function () {
    hookWorkspace();

    stripeEvent('customer.subscription.created', stripeSubscription('active', 'price_growth_m_inr'), 'whsec_wrong')->assertForbidden();
    $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.created'])->assertForbidden();
});

test('a new subscription makes the workspace active on its plan, cycle and renewal date', function () {
    $workspace = hookWorkspace();

    stripeEvent('customer.subscription.created', stripeSubscription('active', 'price_business_y_inr'))->assertOk();

    $workspace->refresh();
    $state = app(WorkspaceBilling::class)->for($workspace);
    expect($workspace->plan->key)->toBe('business')
        ->and($workspace->billing_cycle)->toBe('yearly')
        ->and($workspace->renews_at->toDateString())->toBe('2026-12-15')
        ->and($state->status)->toBe('active')
        ->and($state->plan->key)->toBe('business')
        ->and($workspace->subscriptions()->sole()->stripe_status)->toBe('active');
});

test('a failed payment starts the grace period, tells billing people once per invoice, and turns read-only after it', function () {
    $workspace = hookWorkspace();
    stripeEvent('customer.subscription.created', stripeSubscription('active', 'price_growth_m_inr'))->assertOk();
    Notification::fake();

    $invoice = ['id' => 'in_fail', 'object' => 'invoice', 'customer' => 'cus_hook', 'amount_due' => 2_94_882, 'currency' => 'inr'];
    stripeEvent('invoice.payment_failed', $invoice)->assertOk();
    stripeEvent('invoice.payment_failed', $invoice)->assertOk();
    stripeEvent('customer.subscription.updated', stripeSubscription('past_due', 'price_growth_m_inr'))->assertOk();

    Notification::assertSentToTimes($workspace->owner, PaymentFailed::class, 1);
    Notification::assertSentTo($workspace->owner, PaymentFailed::class, fn (PaymentFailed $n, array $channels, object $u) => $n->toArray($u)['title'] === 'We couldn’t take ₹2,948.82 for Vertex Group'
        && in_array('mail', $channels, true));

    $workspace->refresh();
    expect($workspace->past_due_since?->toDateString())->toBe('2026-11-15');
    $state = app(WorkspaceBilling::class)->for($workspace);
    expect($state->status)->toBe('past_due')->and($state->isReadOnly())->toBeFalse()
        ->and($state->graceEndsAt->toDateString())->toBe('2026-11-22');

    CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    app(WorkspaceBilling::class)->forget($workspace);
    expect(app(WorkspaceBilling::class)->for($workspace)->isReadOnly())->toBeTrue();

    // Paid at last: back to normal.
    stripeEvent('invoice.paid', $invoice)->assertOk();
    stripeEvent('customer.subscription.updated', stripeSubscription('active', 'price_growth_m_inr'))->assertOk();
    app(WorkspaceBilling::class)->forget($workspace);
    expect($workspace->fresh()->past_due_since)->toBeNull()
        ->and(app(WorkspaceBilling::class)->for($workspace->fresh())->isReadOnly())->toBeFalse();
});

test('Stripe’s “trial will end” tells the owner and Admins', function () {
    $workspace = hookWorkspace();
    stripeEvent('customer.subscription.created', stripeSubscription('trialing', 'price_growth_m_inr', ['trial_end' => CarbonImmutable::parse('2026-11-18 10:00')->timestamp]))->assertOk();
    Notification::fake();

    stripeEvent('customer.subscription.trial_will_end', stripeSubscription('trialing', 'price_growth_m_inr', ['trial_end' => CarbonImmutable::parse('2026-11-18 10:00')->timestamp]))->assertOk();

    Notification::assertSentTo($workspace->owner, TrialEnding::class, fn (TrialEnding $n, array $c, object $u) => $n->toArray($u)['title'] === 'Your Coffer trial ends in 3 days');
});

test('a cancelled subscription keeps working to the period end, then the workspace expires into its grace days', function () {
    $workspace = hookWorkspace();
    stripeEvent('customer.subscription.created', stripeSubscription('active', 'price_growth_m_inr'))->assertOk();
    stripeEvent('customer.subscription.updated', stripeSubscription('active', 'price_growth_m_inr', ['cancel_at' => CarbonImmutable::parse('2026-12-15')->timestamp]))->assertOk();

    app(WorkspaceBilling::class)->forget($workspace);
    expect(app(WorkspaceBilling::class)->for($workspace->fresh())->status)->toBe('canceled');

    stripeEvent('customer.subscription.deleted', stripeSubscription('canceled', 'price_growth_m_inr', ['ended_at' => CarbonImmutable::parse('2026-11-15 10:00')->timestamp]))->assertOk();
    app(WorkspaceBilling::class)->forget($workspace);
    $state = app(WorkspaceBilling::class)->for($workspace->fresh());
    expect($state->status)->toBe('expired')->and($state->isReadOnly())->toBeFalse()
        ->and($state->graceEndsAt?->toDateString())->toBe('2026-11-22');
});
