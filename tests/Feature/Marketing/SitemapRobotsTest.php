<?php

declare(strict_types=1);

use App\Http\Controllers\Marketing\SitemapController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/* `/sitemap.xml` and `/robots.txt` (spec §21, D83). */

beforeEach(function () {
    $this->withoutVite();
    config(['app.url' => 'https://coffer.test']);
});

it('lists every public page in the sitemap', function () {
    $response = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = simplexml_load_string($response->getContent());
    $locs = [];
    foreach ($xml->url as $url) {
        $locs[] = (string) $url->loc;
    }

    expect($locs)->toBe([
        'https://coffer.test/',
        'https://coffer.test/pricing',
        'https://coffer.test/features/finance',
        'https://coffer.test/features/timesheets',
        'https://coffer.test/features/vault',
        'https://coffer.test/features/reports',
        'https://coffer.test/security',
        'https://coffer.test/contact',
        'https://coffer.test/terms',
        'https://coffer.test/privacy',
        'https://coffer.test/cookies',
        'https://coffer.test/dpa',
    ]);

    foreach ($locs as $loc) {
        $this->get(str_replace('https://coffer.test', '', $loc))->assertOk();
    }
});

it('keeps crawlers out of the app and points at the sitemap', function () {
    $body = $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($body)
        ->toContain("User-agent: *\n")
        ->toContain("Disallow: /admin\n")
        ->toContain("Disallow: /dashboard\n")
        ->toContain("Disallow: /vault\n")
        ->toContain("Disallow: /settings\n")
        ->toContain('Sitemap: https://coffer.test/sitemap.xml')
        ->not->toContain('Disallow: /pricing');
});

it('disallows every signed-in route in robots.txt', function () {
    $prefixes = SitemapController::DISALLOW;

    $uncovered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
        ->filter(fn (RoutingRoute $route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
        ->map(fn (RoutingRoute $route) => '/'.ltrim($route->uri(), '/'))
        ->reject(fn (string $uri) => collect($prefixes)->contains(fn (string $prefix) => str_starts_with($uri, $prefix)))
        ->values()
        ->all();

    expect($uncovered)->toBe([]);
});
