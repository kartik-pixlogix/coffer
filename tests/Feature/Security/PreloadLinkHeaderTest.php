<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Vite;

test('the preload Link header stays under LiteSpeed’s 8 KB header limit (D146)', function () {
    // Far more chunks than any page has, each with a long hashed name.
    Vite::partialMock()->shouldReceive('preloadedAssets')->andReturn(collect(range(1, 150))->mapWithKeys(fn (int $i) => [
        "https://coffer.example.com/build/assets/chunk-{$i}-AbCdEfGhIjKlMnOp.js" => ['rel="modulepreload"', 'crossorigin'],
    ])->all());

    $link = (string) $this->get(route('login'))->headers->get('Link');

    expect(substr_count($link, 'rel="modulepreload"'))->toBe(20)
        ->and(strlen($link))->toBeLessThan(8000);
});
