<?php

declare(strict_types=1);

use App\Auth\LoginLockout;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\LoginRateLimiter;

/*
| Rate-limit audit (spec §23, D93): every sensitive endpoint carries a throttle. Sign-in is
| limited by App\Auth\LoginLockout (Fortify's LoginRateLimiter binding) instead.
*/

test('sensitive endpoints are throttled', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull("route {$name} is missing");

    $throttles = collect($route->gatherMiddleware())
        ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:'));

    expect($throttles)->not->toBeEmpty("{$name} has no throttle");
})->with([
    'two-factor.login.store', 'register.store', 'password.email', 'password.update', 'password.confirm.store',
    'verification.send', 'session.reauth', 'session.reauth.code', 'google.callback',
    'invites.show', 'invites.accept', 'invites.renew', 'settings.invites.store', 'settings.invites.link', 'settings.invites.resend',
    'marketing.contact.store', 'broken-links.store',
    'vault.unlock', 'vault.items.reveal', 'vault.items.copy', 'vault.items.totp', 'vault.attachments.show',
    'vault.export.store', 'vault.import.store', 'vault.import.commit', 'vault.audit.export', 'vault.health.export',
    'income.export', 'expenses.export', 'capital-accounts.export', 'reports.export', 'reports.trial-balance.export', 'reports.balance-sheet.export',
    'timesheets.import.store', 'profile.password.update', 'billing.checkout', 'billing.portal',
]);

test('sign-in is limited by the lockout, not a route throttle', function () {
    expect(app(LoginRateLimiter::class))->toBeInstanceOf(LoginLockout::class);
});

test('the reset-link form answers 429 after five tries for one address', function () {
    foreach (range(1, 5) as $i) {
        $this->post(route('password.email'), ['email' => 'someone@example.com'])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => 'someone@example.com'])->assertStatus(429);
});

test('the Stripe webhook is exempt from CSRF and checked by its signature instead', function () {
    config(['cashier.webhook.secret' => 'whsec_test']);
    $route = Route::getRoutes()->getByName('cashier.webhook');

    expect($route->gatherMiddleware())->not->toContain('web');

    // No Stripe-Signature header: Cashier's VerifyWebhookSignature refuses it.
    $this->postJson('/stripe/webhook', ['type' => 'invoice.paid'])->assertForbidden();
});
