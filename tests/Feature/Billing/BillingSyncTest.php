<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeStripe;

/*
| Billing QA fixes (D117, D118): webhooks need the secret (B4); the return from Checkout and
| "Refresh from Stripe" sync straight from Stripe (B2); no second subscription (B3);
| payable currencies (B5); no downgrade below usage (B6); trial days match (B7); the
| Stripe price must match the plan (B1 safeguard); customers never see the keys note (F6).
| Stripe is FakeStripe throughout.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    config(['billing.stripe.prices.growth.monthly.inr' => 'price_growth_m_inr']);
});

afterEach(function () {
    FakeStripe::uninstall();
    CarbonImmutable::setTestNow();
});

function syncWorkspace(): object
{
    $owner = User::factory()->create(['name' => 'Aarav Mehta']);
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Vertex Group');
    $company = Company::factory()->create(['workspace_id' => $workspace->id]);
    app(SetCompanyRole::class)->handle($company, $owner, CompanyRole::Admin);

    return (object) ['workspace' => $workspace->refresh(), 'company' => $company, 'owner' => $owner->refresh()];
}

/** A Stripe subscription object on Growth monthly (INR) with a Visa card. */
function liveStripeSubscription(string $customer, string $status = 'active'): array
{
    return [
        'id' => 'sub_live_1', 'object' => 'subscription', 'customer' => $customer, 'status' => $status,
        'metadata' => ['type' => 'default'], 'cancel_at_period_end' => false, 'trial_end' => null,
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_1', 'object' => 'subscription_item', 'quantity' => 1,
            'current_period_end' => CarbonImmutable::parse('2026-12-15')->timestamp,
            'price' => ['id' => 'price_growth_m_inr', 'object' => 'price', 'product' => 'prod_growth'],
        ]]],
        'default_payment_method' => ['id' => 'pm_1', 'object' => 'payment_method', 'card' => ['brand' => 'visa', 'last4' => '4242']],
    ];
}

test('webhooks are refused while STRIPE_WEBHOOK_SECRET is unset (B4)', function () {
    config(['cashier.webhook.secret' => null]);

    $this->postJson('/stripe/webhook', ['id' => 'evt_1', 'type' => 'customer.subscription.created', 'data' => ['object' => []]])
        ->assertForbidden();
});

test('back from Checkout, the subscription and card are synced from Stripe at once (B2)', function () {
    $w = syncWorkspace();
    $w->workspace->forceFill(['stripe_id' => 'cus_sync'])->save();
    FakeStripe::install()->on('get', '/v1/invoices', ['object' => 'list', 'has_more' => false, 'data' => []])->on('get', '/v1/checkout/sessions/cs_test_ok', [
        'id' => 'cs_test_ok', 'object' => 'checkout.session', 'customer' => 'cus_sync',
        'client_reference_id' => (string) $w->workspace->id, 'subscription' => liveStripeSubscription('cus_sync'),
    ]);

    $this->actingAs($w->owner)->get(route('billing.index', ['checkout' => 'done', 'session_id' => 'cs_test_ok']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('checkout', 'synced')
            ->where('state.status', 'active')
            ->where('state.hasSubscription', true)
            ->where('state.paymentMethod', ['brand' => 'Visa', 'last4' => '4242']));

    // Twice is the same as once.
    $this->actingAs($w->owner)->get(route('billing.index', ['checkout' => 'done', 'session_id' => 'cs_test_ok']))->assertOk();
    expect($w->workspace->subscriptions()->count())->toBe(1)
        ->and($w->workspace->fresh()->trial_ends_at)->toBeNull();
});

test('a Checkout Session for another customer is ignored (B2)', function () {
    $w = syncWorkspace();
    $w->workspace->forceFill(['stripe_id' => 'cus_mine'])->save();
    FakeStripe::install()->on('get', '/v1/invoices', ['object' => 'list', 'has_more' => false, 'data' => []])->on('get', '/v1/checkout/sessions/cs_test_other', [
        'id' => 'cs_test_other', 'object' => 'checkout.session', 'customer' => 'cus_someone_else',
        'client_reference_id' => '999999', 'subscription' => liveStripeSubscription('cus_someone_else'),
    ]);

    $this->actingAs($w->owner)->get(route('billing.index', ['checkout' => 'done', 'session_id' => 'cs_test_other']))
        ->assertInertia(fn (Assert $page) => $page->where('checkout', 'done')->where('state.hasSubscription', false));
});

test('"Refresh from Stripe" pulls the subscription; it is for Billing roles only (B2)', function (CompanyRole $role, int $status) {
    $w = syncWorkspace();
    $w->workspace->forceFill(['stripe_id' => 'cus_sync'])->save();
    FakeStripe::install()->on('get', '/v1/subscriptions', ['object' => 'list', 'has_more' => false, 'data' => [liveStripeSubscription('cus_sync')]]);
    $user = $role === CompanyRole::Admin ? $w->owner : userWithRole($role, $w->company);

    $response = $this->actingAs($user)->post(route('billing.sync'));

    if ($status === 403) {
        $response->assertForbidden();
        expect($w->workspace->subscriptions()->count())->toBe(0);

        return;
    }

    $response->assertRedirect()->assertInertiaFlash('toast.message', 'Up to date with Stripe.');
    expect($w->workspace->subscriptions()->sole()->stripe_status)->toBe('active')
        ->and($w->workspace->fresh()->pm_last_four)->toBe('4242');
})->with([
    'admin' => [CompanyRole::Admin, 302],
    'partner' => [CompanyRole::Partner, 403],
    'member' => [CompanyRole::Member, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('a subscription Stripe has but this server missed is synced instead of a second Checkout (B3)', function () {
    $w = syncWorkspace();
    $w->workspace->forceFill(['stripe_id' => 'cus_sync'])->save();
    $stripe = FakeStripe::install()->on('get', '/v1/subscriptions', ['object' => 'list', 'has_more' => false, 'data' => [liveStripeSubscription('cus_sync')]]);

    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly', 'currency' => 'inr'])
        ->assertSessionHasErrors(['plan' => 'This is already your plan.']);

    expect($stripe->sent('post', '/v1/checkout/sessions'))->toBe([])
        ->and($w->workspace->subscriptions()->count())->toBe(1);
});

test('without a price id in the workspace currency, Checkout uses a currency that can be paid (B5)', function () {
    config(['billing.stripe.prices.growth.monthly.inr' => null, 'billing.stripe.prices.growth.monthly.usd' => 'price_growth_m_usd']);
    $w = syncWorkspace();
    $stripe = FakeStripe::install()->customer()->price('price_growth_m_usd', 'growth', 'monthly', 'usd')
        ->on('post', '/v1/checkout/sessions', ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']);

    $this->actingAs($w->owner)->withHeaders(['X-Inertia' => 'true'])
        ->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly'])
        ->assertStatus(409);

    expect($stripe->sent('post', '/v1/checkout/sessions')[0]['params']['line_items'][0]['price'])->toBe('price_growth_m_usd');
});

test('a plan below current usage is refused, saying what is over (B6)', function () {
    config(['billing.stripe.prices.starter.monthly.inr' => 'price_starter_m_inr']);
    $w = syncWorkspace();
    Company::factory()->create(['workspace_id' => $w->workspace->id]);
    foreach (range(1, 3) as $i) {
        userWithRole(CompanyRole::Member, $w->company);
    }
    FakeStripe::install();

    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'starter', 'cycle' => 'monthly', 'currency' => 'inr'])
        ->assertSessionHasErrors(['plan' => 'Starter is too small for this workspace: companies (2 of 1), users (4 of 3). Remove some first, or pick a bigger plan.']);
});

test('Stripe gets the same number of trial days the page shows (B7)', function () {
    $w = syncWorkspace();
    // 13 days and 5 hours left shows as 14 days on Billing.
    $w->workspace->forceFill(['trial_ends_at' => now()->addDays(13)->addHours(5)])->save();
    $stripe = FakeStripe::install()->customer()->price('price_growth_m_inr', 'growth', 'monthly', 'inr')
        ->on('post', '/v1/checkout/sessions', ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']);

    $this->actingAs($w->owner)->get(route('billing.index'))->assertInertia(fn (Assert $page) => $page->where('state.trialDaysLeft', 14));
    $this->actingAs($w->owner)->withHeaders(['X-Inertia' => 'true'])
        ->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly', 'currency' => 'inr'])->assertStatus(409);

    expect($stripe->sent('post', '/v1/checkout/sessions')[0]['params']['subscription_data']['trial_end'])->toBe(now()->addDays(14)->timestamp);
});

test('a Stripe price that doesn’t match the plan is logged and refused (B1 safeguard)', function () {
    $w = syncWorkspace();
    Log::spy();
    $stripe = FakeStripe::install()->customer()->price('price_growth_m_inr', 'growth', 'monthly', 'inr', amount: 99_900);

    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly', 'currency' => 'inr'])
        ->assertSessionHasErrors(['plan' => 'Growth can’t be bought right now: its Stripe price doesn’t match the price shown here. We’ve logged it for the Coffer team; nothing was charged.']);

    expect($stripe->sent('post', '/v1/checkout/sessions'))->toBe([]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'Billing: Stripe price does not match the plan')->once();
});

test('customers never see the note about Stripe keys (F6)', function () {
    $w = syncWorkspace();

    $this->actingAs($w->owner)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('gatewayReady', false)->where('gatewayHint', false));
    $this->actingAs($w->owner)->getJson(route('billing.plans'))->assertJsonPath('gatewayHint', false);
    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly'])
        ->assertSessionHasErrors(['plan' => 'Online payments aren’t available yet. Contact support.']);
});
