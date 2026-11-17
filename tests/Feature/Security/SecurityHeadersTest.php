<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/*
| Response headers (spec §23, D93).
*/

test('app pages send a nonce-based CSP and the hardening headers', function () {
    $books = singleBooks();

    $response = $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->get(route('dashboard'))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $match);

    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->not->toContain('unsafe-eval')
        ->and($match[1] ?? null)->not->toBeNull()
        // The theme script and the Vite tags carry the same nonce.
        ->and((string) $response->getContent())->toContain('nonce="'.$match[1].'"');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect((string) $response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

test('public pages, the health probe and webhooks get the headers too', function () {
    $this->get('/')->assertHeader('X-Frame-Options', 'DENY');
    $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('the admin panel gets the relaxed script policy Filament needs', function () {
    $csp = (string) $this->get('/admin/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("'unsafe-eval'")->toContain("frame-ancestors 'none'");
});

test('HSTS is sent in production only', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('the Vite dev server is allowed only in local while it runs', function () {
    $hot = storage_path('framework/testing-hot-'.getmypid());
    file_put_contents($hot, 'http://[::1]:5174');
    Vite::useHotFile($hot);

    try {
        $middleware = new SecurityHeaders;
        $request = Request::create('/dashboard');

        expect($middleware->policy($request, 'n'))->not->toContain('5174');

        app()->detectEnvironment(fn () => 'local');

        expect($middleware->policy($request, 'n'))->toContain('http://[::1]:5174')->toContain('ws://[::1]:5174');
    } finally {
        @unlink($hot);
    }
});
