<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The public marketing site (spec §21, D83): every page renders for guests with its SEO
| props, Pricing reads the plans table, and signed-in visitors skip the landing page.
*/

beforeEach(fn () => $this->withoutVite());

dataset('marketing pages', [
    'landing' => ['/', 'marketing/landing'],
    'pricing' => ['/pricing', 'marketing/pricing'],
    'finance' => ['/features/finance', 'marketing/feature'],
    'timesheets' => ['/features/timesheets', 'marketing/feature'],
    'vault' => ['/features/vault', 'marketing/feature'],
    'reports' => ['/features/reports', 'marketing/feature'],
    'security' => ['/security', 'marketing/security'],
    'contact' => ['/contact', 'marketing/contact'],
    'terms' => ['/terms', 'marketing/legal'],
    'privacy' => ['/privacy', 'marketing/legal'],
    'cookies' => ['/cookies', 'marketing/legal'],
    'dpa' => ['/dpa', 'marketing/legal'],
]);

it('renders every public page for guests with SEO props', function (string $path, string $component) {
    config(['app.url' => 'https://coffer.test']);

    $this->get($path.'?utm_source=x')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('seo.canonical', 'https://coffer.test'.($path === '/' ? '/' : $path))
            ->where('seo.ogImage', 'https://coffer.test/og-image.png')
            ->where('seo.jsonLd.0.@type', 'Organization'));
})->with('marketing pages');

it('lets signed-in users read every page except the landing page', function (string $path, string $component) {
    $user = userWithRole(CompanyRole::Member);

    $response = $this->actingAs($user)->get($path);

    if ($path === '/') {
        $response->assertRedirect(route('home'));

        return;
    }

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
})->with('marketing pages');

it('has a brand OG image in public', function () {
    $size = getimagesize(public_path('og-image.png'));

    expect($size)->not->toBeFalse()
        ->and($size[0])->toBe(1200)
        ->and($size[1])->toBe(630);
});

it('passes the feature and legal slugs, and 404s unknown ones', function () {
    $this->get('/features/vault')->assertInertia(fn (Assert $page) => $page->where('feature', 'vault'));
    $this->get('/dpa')->assertInertia(fn (Assert $page) => $page->where('page', 'dpa'));
    $this->get('/features/payroll')->assertNotFound();
});

it('shows Pricing from the plans table and lists offers in JSON-LD', function () {
    Plan::query()->where('key', 'growth')->update(['price_monthly_inr' => 3_00_000]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('plans', 4)
            ->where('plans.1.key', 'growth')
            ->where('plans.1.prices.monthly.inr', 3_00_000)
            ->where('plans.3.customPricing', true)
            ->where('seo.jsonLd.1.@type', 'SoftwareApplication')
            ->has('seo.jsonLd.1.offers', 3)
            ->where('seo.jsonLd.1.offers.1.price', '3000.00')
            ->where('seo.jsonLd.1.offers.1.priceCurrency', 'INR'));
});

it('preselects the contact topic from the query string', function () {
    $this->get('/contact?topic=sales')->assertInertia(fn (Assert $page) => $page
        ->where('topic', 'sales')
        ->has('topics', 4)
        ->where('sent', null));

    $this->get('/contact?topic=nonsense')->assertInertia(fn (Assert $page) => $page->where('topic', 'demo'));
});

it('trials the plan a Pricing CTA picked', function () {
    $this->get('/register?plan=starter')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('plan', 'starter'))
        ->assertSessionHas('signup.plan', 'starter');

    $this->post('/register', [
        'name' => 'Asha Rao',
        'company_name' => 'Asha Retail',
        'email' => 'asha@example.com',
        'password' => 'a long enough sentence',
        'terms' => '1',
        'plan' => 'starter',
    ])->assertRedirect();

    $workspace = Workspace::query()->where('owner_id', User::query()->where('email', 'asha@example.com')->value('id'))->sole();

    expect($workspace->plan?->key)->toBe('starter');
});

it('ignores an Enterprise or unknown plan and trials the default plan', function (string $plan) {
    $this->get('/register?plan='.$plan)
        ->assertInertia(fn (Assert $page) => $page->where('plan', null))
        ->assertSessionMissing('signup.plan');

    $this->post('/register', [
        'name' => 'Asha Rao',
        'company_name' => 'Asha Retail',
        'email' => 'asha@example.com',
        'password' => 'a long enough sentence',
        'terms' => '1',
        'plan' => $plan,
    ])->assertRedirect();

    expect(Workspace::query()->sole()->plan?->key)->toBe(config('billing.trial_plan'));
})->with(['enterprise', 'platinum']);
