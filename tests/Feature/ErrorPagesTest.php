<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The designed error pages (D89): outside debug mode each status renders the
| `errors/error` Inertia page; in debug mode the framework's pages stay.
*/

beforeEach(function () {
    config(['app.debug' => false]);
    $this->withoutVite();

    Route::middleware('web')->group(function () {
        Route::get('_test/status/{status}', fn (int $status) => abort($status));
        Route::get('_test/boom', fn () => throw new RuntimeException('Boom'));
    });
});

it('renders an unknown URL as the 404 page for a guest', function () {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 404)
            ->where('path', 'localhost/no-such-page')
            ->where('landing', null)
            ->where('places', []));
});

it('renders the 404 inside the shell for a signed-in user, with places the role may open', function () {
    $this->actingAs(userWithRole(CompanyRole::Admin))
        ->get('/expenses/reports/q3-2025')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', 404)
            ->where('landing.label', 'Go to the dashboard')
            ->where('places.0.label', 'Expenses')
            ->has('auth.user')
            ->has('currentCompany'));
});

it('keeps a member to the places their role opens', function () {
    $this->actingAs(userWithRole(CompanyRole::Member))
        ->get('/no-such-page')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('reports', null)
            ->where('landing.label', 'Go to my timesheets')
            ->where('places', fn ($places) => collect($places)->pluck('label')->doesntContain('Settings')));
});

it('renders each status as the error page', function (int $status, string $url) {
    $this->get($url)
        ->assertStatus($status)
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error')
            ->where('status', $status));
})->with([
    '403 for a guest' => [403, '/_test/status/403'],
    '404' => [404, '/_test/status/404'],
    '419' => [419, '/_test/status/419'],
    '500' => [500, '/_test/boom'],
    '503' => [503, '/_test/status/503'],
]);

it('renders the 503 page in maintenance mode', function () {
    app()->maintenanceMode()->activate([]);

    try {
        $this->get('/pricing')
            ->assertStatus(503)
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/error')
                ->where('status', 503)
                ->where('landing', null));
    } finally {
        app()->maintenanceMode()->deactivate();
    }
});

it('keeps the permission-denied page for a signed-in 403', function () {
    $this->actingAs(userWithRole(CompanyRole::Member))
        ->get('/_test/status/403')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('errors/403'));
});

it('leaves the debug pages alone in debug mode', function () {
    config(['app.debug' => true]);

    $this->get('/_test/boom')
        ->assertStatus(500)
        ->assertSee('Boom');
});

it('answers JSON requests with JSON', function () {
    $this->getJson('/no-such-page')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

it('keeps the session-expired answer for Inertia requests (D30)', function () {
    $this->withHeaders(['X-Inertia' => 'true'])
        ->get('/dashboard')
        ->assertUnauthorized()
        ->assertHeader('X-Coffer-Session', 'expired');
});

it('logs a reported broken link', function () {
    Log::spy();

    $this->from('/no-such-page')
        ->post('/broken-links', ['path' => 'localhost/no-such-page'])
        ->assertRedirect('/no-such-page');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Broken link reported'
            && $context['path'] === 'localhost/no-such-page');
});

it('validates the reported link', function () {
    $this->post('/broken-links', ['path' => ''])
        ->assertSessionHasErrors('path');
});
