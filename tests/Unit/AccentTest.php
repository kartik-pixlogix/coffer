<?php

declare(strict_types=1);

use App\Support\Accent;

test('Coffer lime is the default and adds no overrides', function () {
    $accent = Accent::default();

    expect($accent->isDefault())->toBeTrue()
        ->and($accent->css())->toBeNull()
        ->and($accent->toStored())->toBeNull()
        ->and($accent->stops)->toBe(['#d6fe68', '#e2ff8f', '#f0ffc4']);
});

test('every preset keeps dark text readable (AA) on all three stops', function () {
    foreach (array_keys(Accent::presets()) as $key) {
        $accent = Accent::make($key);

        expect($accent->onColor())->toBe(Accent::DARK_TEXT)
            ->and($accent->minContrast())->toBeGreaterThanOrEqual(4.5);
    }
});

// Same fixture as resources/js/lib/accent.test.ts: the preview must match the server.
test('a custom gradient renders the token overrides, with white text on dark colours', function () {
    $accent = Accent::make(Accent::CUSTOM, ['#1E3A8A', '#1d4ed8', '#312e81'], 90);

    expect($accent->onColor())->toBe(Accent::LIGHT_TEXT)
        ->and($accent->toStored())->toBe(['preset' => 'custom', 'stops' => ['#1e3a8a', '#1d4ed8', '#312e81'], 'angle' => 90])
        ->and($accent->css())->toBe(
            ':root{--lime:#1e3a8a;--lime-mid:#1d4ed8;--lime-tint:#312e81'
            .';--gradient-lime:linear-gradient(90deg,#1e3a8a 0%,#1d4ed8 55%,#312e81 100%)'
            .';--on-lime:#ffffff;--selected:#bcc4dc}'
            .':root[data-theme="dark"]{--selected:#161a27}'
        );
});

test('only #rrggbb colours and 0–360 degrees are accepted', function (array $stops, int $angle) {
    Accent::make(Accent::CUSTOM, $stops, $angle);
})->with([
    'css injection' => [['#fff000;}body{display:none', '#000000', '#000000'], 90],
    'named colour' => [['red', '#000000', '#000000'], 90],
    'short hex' => [['#fff', '#000000', '#000000'], 90],
    'two stops' => [['#ffffff', '#000000'], 90],
    'angle' => [['#ffffff', '#000000', '#111111'], 400],
])->throws(InvalidArgumentException::class);

test('an unreadable stored value falls back to lime', function (mixed $stored) {
    expect(Accent::fromStored($stored)->isDefault())->toBeTrue();
})->with([
    'null' => [null],
    'unknown preset' => [['preset' => 'neon']],
    'bad colours' => [['preset' => 'custom', 'stops' => ['x', 'y', 'z'], 'angle' => 90]],
]);
