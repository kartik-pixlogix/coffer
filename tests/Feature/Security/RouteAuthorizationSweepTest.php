<?php

declare(strict_types=1);

use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Laravel\Horizon\Http\Middleware\Authenticate as HorizonAuthenticate;

/*
| Authorisation sweep (spec §23, D93). Every registered route is either public on purpose
| (listed below with the reason), or signed-in plus an authorisation middleware (`page:` from
| the permission matrix, `can:`, or Filament's panel gate), or signed-in and listed with the
| reason it needs no page permission (the person's own account, or a policy in the action).
| A new route that fits none of these fails here until someone decides which it is.
*/

/** Public on purpose. Keys are route names, or a URI prefix ending in `*`. */
const PUBLIC_ROUTES = [
    'welcome' => 'marketing home (signed-in visitors are redirected to /home)',
    'marketing.*' => 'marketing and legal pages, contact form (throttled)',
    'robots' => 'robots.txt',
    'sitemap' => 'sitemap.xml',
    'login' => 'guest sign-in form', 'login.store' => 'sign-in (LoginLockout: 5 tries, then 15 minutes)',
    'register' => 'guest sign-up form', 'register.store' => 'sign-up (throttle:auth-forms)',
    'password.request' => 'forgot-password form', 'password.email' => 'reset link (throttle:auth-forms)',
    'password.reset' => 'reset form, token-checked', 'password.update' => 'reset (token-checked, throttle:auth-forms)',
    'two-factor.login' => 'challenge form, needs the half-signed-in session', 'two-factor.login.store' => 'challenge (throttle:two-factor)',
    'google.redirect' => 'Google sign-in (throttled)', 'google.callback' => 'Google sign-in callback (state-checked, throttled)',
    'invites.show' => 'invite link, token is the secret (throttled)', 'invites.accept' => 'token-checked (throttled)',
    'invites.decline' => 'token-checked (throttled)', 'invites.renew' => 'token-checked (throttled)',
    'session.token' => 'fresh CSRF token for the re-auth dialog (D30, throttled)',
    'session.reauth' => 're-auth with password (D30, throttled)', 'session.reauth.code' => 're-auth with a 2FA code (D30, throttled)',
    'broken-links.store' => '404 page "tell us" (throttled, logs only)',
    'cashier.webhook' => 'Stripe webhook, signature-checked by Cashier (CSRF-exempt)',
    'cashier.payment' => 'Cashier payment confirmation page, signed redirect URL',
    'storage.local' => 'local disk temporary URLs, signature-checked by the framework',
    'storage.local.upload' => 'local disk temporary upload URLs, signature-checked by the framework',
    'health' => 'deep health check: bearer token or platform admin, checked in the controller (404 otherwise, D97)',
    'dev.ui' => 'UI gallery, EnsureLocalEnvironment (404 outside local)',
    'filament.admin.auth.login' => 'platform admin sign-in (Filament)',
    'livewire-*' => 'Livewire assets and endpoints; components authorise themselves (only the /admin panel uses Livewire)',
    '_inertia/*' => 'Inertia devtools, behind its own Authorize middleware (registered in local only)',
    'filament.exports.download' => 'Filament export download: its controller checks the signed guard and the export owner (no export actions are used)',
    'filament.imports.failed-rows.download' => 'Filament import failures: its controller checks the signed guard and the import owner (no import actions are used)',
    'up' => 'framework liveness probe, no data',
    '{fallbackPlaceholder}' => 'the 404 page (D89)',
];

/** Signed in, no page permission needed. */
const OWN_ACCOUNT_ROUTES = [
    'home' => 'role landing redirect',
    'logout' => 'sign out',
    'impersonation.stop' => 'ends the platform admin’s impersonation; the service checks the session (D86)',
    'companies.*' => 'create a company / switch to one the user belongs to (membership checked in the action)',
    'profile.*' => 'the user’s own profile, password, sessions, 2FA (spec §17)',
    'theme.update' => 'the user’s own theme',
    'notifications.*' => 'the user’s own notifications (D78)',
    'settings.notifications' => 'the user’s own notification settings (D78)',
    'settings.notifications.update' => 'the user’s own notification settings (D78)',
    'billing.plans' => 'plan list for the Upgrade modal, read-only (D80)',
    'access-requests.*' => 'asking an Admin for access; the request policy checks ownership',
    'verification.*' => 'email verification (Fortify, signed + throttled)',
    'password.confirm' => 'confirm password (Fortify)', 'password.confirm.store' => 'confirm password (throttle:auth-forms)',
    'password.confirmation' => 'confirm-password status (Fortify)',
    'two-factor.*' => 'the user’s own 2FA setup (Fortify, password-confirmed)',
    'filament.admin.auth.logout' => 'platform admin sign out',
    'privacy.*' => 'the user’s own data export and account deletion; workspace export / deletion check ownership in the controller; downloads are signed and owner-checked (D98)',
];

function routeKeyMatches(Route $route, array $list): ?string
{
    foreach ($list as $key => $reason) {
        $hit = str_ends_with($key, '*')
            ? (Str::is($key, (string) $route->getName()) || str_starts_with($route->uri(), rtrim($key, '*')))
            : ($route->getName() === $key || $route->uri() === $key);

        if ($hit) {
            return $reason;
        }
    }

    return null;
}

test('every route is public on purpose, or signed in and authorised', function () {
    $problems = [];

    foreach (Router::getRoutes() as $route) {
        /** @var Route $route */
        $middleware = collect($route->gatherMiddleware())->map(fn ($m): string => is_string($m) ? $m : get_class($m));
        $label = implode('|', $route->methods()).' '.$route->uri().' ('.($route->getName() ?? 'unnamed').')';

        if (routeKeyMatches($route, PUBLIC_ROUTES) !== null) {
            continue;
        }

        $signedIn = $middleware->contains(fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:')
            || $m === FilamentAuthenticate::class || $m === HorizonAuthenticate::class);

        if (! $signedIn) {
            $problems[] = "{$label}: not signed in and not on the public list";

            continue;
        }

        $authorised = $middleware->contains(fn (string $m): bool => str_starts_with($m, 'page:') || str_starts_with($m, 'can:')
            || $m === FilamentAuthenticate::class || $m === HorizonAuthenticate::class);

        if (! $authorised && routeKeyMatches($route, OWN_ACCOUNT_ROUTES) === null) {
            $problems[] = "{$label}: signed in but no page:/can: middleware and not on the own-account list";
        }
    }

    expect($problems)->toBe([]);
});

test('the allowlists only name routes that exist', function () {
    $routes = collect(Router::getRoutes()->getRoutes());

    // Inertia's devtools routes are registered in local only.
    foreach (array_diff([...array_keys(PUBLIC_ROUTES), ...array_keys(OWN_ACCOUNT_ROUTES)], ['_inertia/*']) as $key) {
        $exists = $routes->contains(fn (Route $route): bool => str_ends_with($key, '*')
            ? (Str::is($key, (string) $route->getName()) || str_starts_with($route->uri(), rtrim($key, '*')))
            : ($route->getName() === $key || $route->uri() === $key));

        expect($exists)->toBeTrue("{$key} is allowlisted but no such route exists");
    }
});
