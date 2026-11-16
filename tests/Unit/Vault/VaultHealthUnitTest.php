<?php

declare(strict_types=1);

use App\Services\Vault\PwnedPasswords;
use App\Services\Vault\VaultHealth;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('grades follow the board: Excellent, Good, Fair, Needs work', function () {
    expect(VaultHealth::grade(95))->toBe('excellent')
        ->and(VaultHealth::grade(82))->toBe('good')
        ->and(VaultHealth::grade(60))->toBe('fair')
        ->and(VaultHealth::grade(59))->toBe('needs work');
});

test('the range check matches the suffix, ignores padding rows and caches each prefix', function () {
    $hash = strtoupper(sha1('correct horse battery staple'));
    Http::fake([
        'api.pwnedpasswords.com/range/*' => Http::response(implode("\r\n", [
            '0018A45C4D1DEF81644B54AB7F969B88D65:1',
            substr($hash, 5).':3',
            'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:0',
        ])),
    ]);

    $pwned = new PwnedPasswords;
    expect($pwned->isPwned('correct horse battery staple'))->toBeTrue();

    // Asking again uses the cached range: no second request.
    expect($pwned->isPwned('correct horse battery staple'))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $r) => $r->url() === PwnedPasswords::ENDPOINT.substr($hash, 0, 5) && $r->header('Add-Padding') === ['true']);
});

test('a padded (count 0) match is not a breach, and a failed call is unknown, logged without the hash', function () {
    $hash = strtoupper(sha1('demo-never-pwned'));
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':0')]);
    expect((new PwnedPasswords)->isPwned('demo-never-pwned'))->toBeFalse();

    Log::spy();
    Http::fake(['*' => Http::response('', 503)]);
    expect((new PwnedPasswords)->isPwned('demo-other'))->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => ! str_contains($message, strtoupper(sha1('demo-other'))));
});
