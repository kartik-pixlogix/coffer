<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PaymentGateway;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeStripe;

/**
 * Billing (spec §20, Billing.dc.html, D80): owner and Admins only; plan and trial, live
 * usage, invoices from Stripe, Checkout, the portal and billing details. Stripe is never
 * called for real: FakeStripe answers its HTTP API.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    FakeStripe::uninstall();
    CarbonImmutable::setTestNow();
});

/** A workspace on the Growth trial with one company and its Admin. */
function trialWorkspace(string $email = 'aarav@example.test'): object
{
    $owner = User::factory()->create(['name' => 'Aarav Mehta', 'email' => $email]);
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Vertex Group');
    $company = Company::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Vertex Labs Pvt Ltd']);
    app(SetCompanyRole::class)->handle($company, $owner, CompanyRole::Admin);

    return (object) ['workspace' => $workspace->refresh(), 'company' => $company, 'owner' => $owner->refresh()];
}

test('a new workspace gets the 14-day trial on the trial plan', function () {
    $w = trialWorkspace();

    expect($w->workspace->plan->key)->toBe('growth')
        ->and($w->workspace->trial_ends_at->toDateString())->toBe('2026-11-29')
        ->and($w->workspace->billing_currency)->toBe('inr');
});

test('Billing is for the workspace owner and Admins only', function (CompanyRole $role, int $status) {
    $w = trialWorkspace();
    $user = userWithRole($role, $w->company);

    $this->actingAs($user)->get(route('billing.index'))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 403],
    'member' => [CompanyRole::Member, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('the workspace owner opens Billing from a company where they aren’t an Admin', function () {
    $w = trialWorkspace();
    app(SetCompanyRole::class)->handle($w->company, $w->owner, CompanyRole::Partner);

    $this->actingAs($w->owner->refresh())->get(route('billing.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.pages.billing', true));
    $this->actingAs(userWithRole(CompanyRole::Partner, $w->company))->get(route('billing.index'))->assertForbidden();
});

test('the page shows the trial, the price, live usage and no invoices before Stripe knows the workspace', function () {
    $w = trialWorkspace();
    userWithRole(CompanyRole::Partner, $w->company);
    FakeStripe::install();

    $this->actingAs($w->owner)->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/index')
            ->where('state.status', 'trial')
            ->where('state.plan', ['key' => 'growth', 'name' => 'Growth'])
            ->where('state.trialDaysLeft', 14)
            ->where('state.trialEndsAt', '29 Nov 2026')
            ->where('state.readOnly', false)
            ->where('usage.0', ['key' => 'companies', 'label' => 'Companies', 'used' => 1, 'limit' => 5, 'detail' => 'Vertex Labs Pvt Ltd'])
            ->where('usage.1.used', 2)
            ->where('usage.1.limit', 15)
            ->where('usage.2.limit', 25)
            ->where('invoices', [])
            ->where('gatewayReady', true)
            ->has('plans', 4)
            ->where('plans.1.key', 'growth')
            ->where('plans.1.prices.monthly.inr', 2_49_900)
            ->where('plans.1.yearlySaving.inr', 16)
            ->where('details.email', 'aarav@example.test')
            ->has('can.pages'));
});

test('invoices come from Stripe with the plan, period and an Indian-format amount', function () {
    config(['billing.stripe.prices.growth.monthly.inr' => 'price_growth_m_inr']);
    $w = trialWorkspace();
    $w->workspace->forceFill(['stripe_id' => 'cus_test123'])->save();
    $stripe = FakeStripe::install();
    $stripe->on('get', '/v1/invoices', ['object' => 'list', 'has_more' => false, 'data' => [[
        'id' => 'in_123', 'object' => 'invoice', 'number' => 'INV-2026-011', 'status' => 'paid', 'currency' => 'inr',
        'created' => CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Kolkata')->timestamp, 'total' => 2_94_882, 'subtotal' => 2_49_900,
        'period_start' => 0, 'period_end' => 0, 'customer' => 'cus_test123',
        'lines' => ['object' => 'list', 'data' => [[
            'id' => 'il_1', 'object' => 'line_item', 'description' => '1 × Growth',
            'period' => ['start' => CarbonImmutable::parse('2026-11-01', 'Asia/Kolkata')->timestamp, 'end' => CarbonImmutable::parse('2026-12-01', 'Asia/Kolkata')->timestamp],
            'pricing' => ['price_details' => ['price' => 'price_growth_m_inr']],
        ]]],
    ]]]);

    $this->actingAs($w->owner)->get(route('billing.index'))
        ->assertInertia(fn (Assert $page) => $page->where('invoices', [[
            'id' => 'in_123', 'number' => 'INV-2026-011', 'date' => '1 Nov 2026', 'period' => '1 – 30 Nov 2026',
            'plan' => 'Growth · monthly', 'amount' => '₹2,948.82', 'status' => 'paid',
        ]])->where('invoicesFailed', false));

    expect($stripe->sent('get', '/v1/invoices')[0]['params']['customer'])->toBe('cus_test123');
});

test('a Stripe outage leaves the page up and says so', function () {
    $w = trialWorkspace();
    $w->workspace->forceFill(['stripe_id' => 'cus_test123'])->save();
    FakeStripe::install()->on('get', '/v1/invoices', ['error' => ['message' => 'Down', 'type' => 'api_error']], 500);

    $this->actingAs($w->owner)->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('invoices', [])->where('invoicesFailed', true));
});

test('Checkout starts a subscription for the trial’s remaining days and sends the browser to Stripe', function () {
    config(['billing.stripe.prices.business.yearly.inr' => 'price_business_y_inr']);
    $w = trialWorkspace();
    $stripe = FakeStripe::install()->customer()
        ->on('post', '/v1/checkout/sessions', ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);

    $this->actingAs($w->owner)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('billing.checkout'), ['plan' => 'business', 'cycle' => 'yearly', 'currency' => 'inr'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test_1');

    $session = $stripe->sent('post', '/v1/checkout/sessions')[0]['params'];
    expect($session['mode'])->toBe('subscription')
        ->and($session['line_items'][0]['price'])->toBe('price_business_y_inr')
        ->and($session['subscription_data']['trial_end'])->toBe($w->workspace->trial_ends_at->timestamp)
        ->and($session['client_reference_id'])->toBe((string) $w->workspace->id)
        ->and($session['success_url'])->toBe(route('billing.index', ['checkout' => 'done']))
        ->and($stripe->sent('post', '/v1/customers')[0]['params']['metadata'])->toBe(['workspace_id' => (string) $w->workspace->id])
        ->and($w->workspace->fresh()->stripe_id)->toBe('cus_test123');
});

test('Checkout refuses Enterprise, an unknown plan, a plan without a price id, and a server without keys', function () {
    $w = trialWorkspace();

    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly'])
        ->assertSessionHasErrors(['plan' => 'Payments aren’t set up on this server yet. Add the Stripe keys (docs/BILLING_TESTING.md).']);

    FakeStripe::install();
    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'enterprise', 'cycle' => 'monthly'])
        ->assertSessionHasErrors(['plan' => 'Enterprise is set up with our team. Talk to us and we’ll switch your plan.']);
    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'platinum', 'cycle' => 'monthly'])->assertSessionHasErrors('plan');
    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'weekly'])->assertSessionHasErrors('cycle');
    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly'])
        ->assertSessionHasErrors(['plan' => 'Growth isn’t set up for monthly payments in INR yet.']);
});

test('a subscribed workspace swaps plan and cycle instead of a new checkout', function () {
    $w = trialWorkspace();
    FakeStripe::install();
    $w->workspace->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active', 'stripe_price' => 'price_growth_m_inr', 'quantity' => 1]);
    $gateway = new class implements PaymentGateway
    {
        public array $swaps = [];

        public function checkout(Workspace $workspace, Plan $plan, string $cycle, string $currency, string $successUrl, string $cancelUrl): string
        {
            throw new RuntimeException('Checkout should not run for a subscribed workspace.');
        }

        public function swap(Workspace $workspace, Plan $plan, string $cycle, string $currency): void
        {
            $this->swaps[] = [$plan->key, $cycle, $currency];
        }

        public function portal(Workspace $workspace, string $returnUrl): string
        {
            return 'https://billing.stripe.com/p/session/x';
        }

        public function invoices(Workspace $workspace): array
        {
            return [];
        }

        public function downloadInvoice(Workspace $workspace, string $invoiceId): Response
        {
            return response('pdf');
        }
    };
    $this->app->instance(PaymentGateway::class, $gateway);

    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'business', 'cycle' => 'monthly'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'You’re on Business now. The new limits are on.');

    expect($gateway->swaps)->toBe([['business', 'monthly', 'inr']])
        ->and($w->workspace->fresh()->plan->key)->toBe('business');
});

test('the Billing Portal opens on Stripe for the workspace’s customer', function () {
    $w = trialWorkspace();
    $stripe = FakeStripe::install()->customer('cus_portal')
        ->on('post', '/v1/billing_portal/sessions', ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1']);

    $this->actingAs($w->owner)->get(route('billing.portal'))
        ->assertRedirect('https://billing.stripe.com/p/session/test_1');

    expect($stripe->sent('post', '/v1/billing_portal/sessions')[0]['params'])->toMatchArray(['customer' => 'cus_portal', 'return_url' => route('billing.index')]);
});

test('billing details save with a checked GSTIN', function () {
    $w = trialWorkspace();

    $this->actingAs($w->owner)->put(route('billing.details'), ['email' => 'accounts@vertexlabs.in', 'name' => 'Vertex Labs Pvt Ltd, Pune', 'gstin' => '27abcde1234f1z5'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Billing details saved. New invoices use them.');
    expect($w->workspace->fresh())->billing_email->toBe('accounts@vertexlabs.in')->billing_gstin->toBe('27ABCDE1234F1Z5')
        ->and($w->workspace->fresh()->stripeName())->toBe('Vertex Labs Pvt Ltd, Pune');

    $this->actingAs($w->owner)->put(route('billing.details'), ['email' => 'nope', 'gstin' => '1234'])->assertSessionHasErrors(['email', 'gstin']);
});

test('the Billing actions are owner and Admin only; invoice ids are checked', function () {
    $w = trialWorkspace();
    $partner = userWithRole(CompanyRole::Partner, $w->company);

    $this->actingAs($partner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly'])->assertForbidden();
    $this->actingAs($partner)->get(route('billing.portal'))->assertForbidden();
    $this->actingAs($partner)->put(route('billing.details'), ['email' => 'x@example.test'])->assertForbidden();
    $this->actingAs($partner)->get(route('billing.invoice', 'in_123'))->assertForbidden();
    $this->actingAs($w->owner)->get(route('billing.invoice', '../../etc'))->assertNotFound();
});

test('the Upgrade modal’s plan list is open to every role in the company', function (CompanyRole $role) {
    $w = trialWorkspace();
    $user = userWithRole($role, $w->company);

    $this->actingAs($user)->getJson(route('billing.plans'))
        ->assertOk()
        ->assertJsonCount(4, 'plans')
        ->assertJsonPath('current', ['plan' => 'growth', 'cycle' => 'monthly', 'currency' => 'inr', 'status' => 'trial', 'hasSubscription' => false])
        ->assertJsonPath('workspace.owner', 'Aarav Mehta')
        ->assertJsonPath('workspace.companies', 1);
})->with([CompanyRole::Admin, CompanyRole::Partner, CompanyRole::Member, CompanyRole::Viewer]);

test('another workspace’s billing is never reachable', function () {
    $w = trialWorkspace();
    $other = trialWorkspace('kabir@example.test');

    $this->actingAs($w->owner)->post(route('companies.switch', $other->company))->assertNotFound();
});

test('a cancelled plan still in its paid period is resumed in place, never bought twice', function () {
    $w = trialWorkspace();
    FakeStripe::install();
    $w->workspace->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active', 'stripe_price' => 'price_growth_m_inr', 'quantity' => 1, 'ends_at' => now()->addDays(10)]);
    $gateway = new class implements PaymentGateway
    {
        public array $swaps = [];

        public function checkout(Workspace $workspace, Plan $plan, string $cycle, string $currency, string $successUrl, string $cancelUrl): string
        {
            throw new RuntimeException('A cancelled-but-paid subscription must not start a second one.');
        }

        public function swap(Workspace $workspace, Plan $plan, string $cycle, string $currency): void
        {
            $this->swaps[] = [$plan->key, $cycle, $currency];
        }

        public function portal(Workspace $workspace, string $returnUrl): string
        {
            return '';
        }

        public function invoices(Workspace $workspace): array
        {
            return [];
        }

        public function downloadInvoice(Workspace $workspace, string $invoiceId): Response
        {
            return response('pdf');
        }
    };
    $this->app->instance(PaymentGateway::class, $gateway);

    $this->actingAs($w->owner)->post(route('billing.checkout'), ['plan' => 'growth', 'cycle' => 'monthly'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Growth is back on. It renews as usual; nothing was cancelled.');

    expect($gateway->swaps)->toBe([['growth', 'monthly', 'inr']]);
});
