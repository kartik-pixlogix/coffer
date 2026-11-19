<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/*
| G8/W1 (D112): Laravel 13's PreventRequestForgery accepts a POST without a token when the
| browser says `Sec-Fetch-Site: same-origin` (a forbidden header page scripts can't set),
| and otherwise needs the session token. Cross-site posts without one are refused (419).
| The framework skips the check while running tests, so these drive the middleware itself.
*/

/** The app's CSRF middleware with the unit-test bypass turned off. */
function csrfGuard(): PreventRequestForgery
{
    return new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    };
}

function postFrom(string $uri, array $headers): Request
{
    $request = Request::create($uri, 'POST', ['description' => 'x']);
    $request->headers->add($headers);
    $request->setLaravelSession(app('session.store'));

    return $request;
}

test('a cross-site POST without a token is refused', function (string $uri, array $headers) {
    expect(fn () => csrfGuard()->handle(postFrom($uri, $headers), fn () => new Response('ok')))
        ->toThrow(TokenMismatchException::class);
})->with([
    'expenses, cross-site' => ['/expenses', ['Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site']],
    'contact, cross-site' => ['/contact', ['Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site']],
    'contact, same-site subdomain' => ['/contact', ['Sec-Fetch-Site' => 'same-site']],
    'no fetch metadata at all' => ['/expenses', []],
]);

test('a same-origin browser POST passes on its fetch metadata; a matching token passes too', function () {
    expect(csrfGuard()->handle(postFrom('/expenses', ['Sec-Fetch-Site' => 'same-origin']), fn () => new Response('ok'))->getContent())->toBe('ok');

    app('session.store')->regenerateToken();
    $request = postFrom('/contact', ['Sec-Fetch-Site' => 'cross-site', 'X-CSRF-TOKEN' => app('session.store')->token()]);
    expect(csrfGuard()->handle($request, fn () => new Response('ok'))->getContent())->toBe('ok');
});

test('only the Stripe webhook skips the check, and the session cookie is SameSite=lax', function () {
    expect(csrfGuard()->getExcludedPaths())->toBe(['stripe/*'])
        ->and(config('session.same_site'))->toBe('lax');
});

test('a refused token renders the branded 419', function () {
    Route::post('/__csrf-probe', fn () => throw new TokenMismatchException('CSRF token mismatch.'))->middleware('web');

    $this->post('/__csrf-probe')->assertStatus(419);
});
