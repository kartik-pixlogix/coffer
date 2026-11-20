<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Accent;

test('a guest can’t save colours', function () {
    $this->patch(route('accent.update'), ['preset' => 'sky'])->assertRedirect(route('login'));
});

test('a preset is saved on the user and rendered in the head before first paint (D147)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('accent.update'), ['preset' => 'sky'])->assertRedirect();

    expect($user->fresh()->accent)->toBe(['preset' => 'sky']);

    $this->actingAs($user)->get(route('companies.create'))
        ->assertSee('<style id="cf-accent"', false)
        ->assertSee('--lime:#8ccbff', false);
});

test('a custom gradient is saved with its colours and angle', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('accent.update'), [
        'preset' => 'custom',
        'stops' => ['#FF0080', '#7928CA', '#2AFADF'],
        'angle' => 45,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->accent)->toBe(['preset' => 'custom', 'stops' => ['#ff0080', '#7928ca', '#2afadf'], 'angle' => 45]);
});

test('resetting to lime clears the saved value', function () {
    $user = User::factory()->create(['accent' => ['preset' => 'rose']]);

    $this->actingAs($user)->patch(route('accent.update'), ['preset' => Accent::DEFAULT]);

    expect($user->fresh()->accent)->toBeNull();
});

test('anything but #rrggbb colours is refused, so nothing can be injected into the CSS', function (array $payload, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('accent.update'), $payload)->assertSessionHasErrors($field);

    expect($user->fresh()->accent)->toBeNull();
})->with([
    'unknown preset' => [['preset' => 'neon'], 'preset'],
    'css injection' => [['preset' => 'custom', 'stops' => ['#fff;}body{display:none}', '#000000', '#000000'], 'angle' => 90], 'stops.0'],
    'two colours' => [['preset' => 'custom', 'stops' => ['#ffffff', '#000000'], 'angle' => 90], 'stops'],
    'angle out of range' => [['preset' => 'custom', 'stops' => ['#ffffff', '#000000', '#111111'], 'angle' => 720], 'angle'],
]);

test('the shared page props carry the gradient and its text colour', function () {
    $user = User::factory()->create(['accent' => ['preset' => 'custom', 'stops' => ['#1e3a8a', '#1d4ed8', '#312e81'], 'angle' => 90]]);

    $this->actingAs($user)->get(route('companies.create'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.accent.preset', 'custom')
            ->where('auth.user.accent.onColor', '#ffffff'));
});
