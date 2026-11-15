<?php

declare(strict_types=1);

use App\Enums\Theme;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** The opening <html …> tag of a full-page response. */
function htmlTag(TestResponse $response): string
{
    preg_match('/<html\b[^>]*>/', (string) $response->getContent(), $match);

    return $match[0] ?? '';
}

test('guests cannot save a theme', function () {
    $this->patch(route('theme.update'), ['theme' => 'dark'])
        ->assertRedirect(route('login'));
});

test('a user can save their theme', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('dashboard'))
        ->patch(route('theme.update'), ['theme' => 'dark']);

    $response->assertRedirect(route('dashboard'))
        ->assertPlainCookie(Theme::COOKIE, 'dark');

    expect($user->refresh()->theme)->toBe(Theme::Dark);
});

test('the theme must be light or dark', function (mixed $value) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('theme.update'), ['theme' => $value])
        ->assertSessionHasErrors('theme');

    expect($user->refresh()->theme)->toBeNull();
})->with(['system', 'blue', '', null]);

test('the page renders with the user theme before any script runs', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);

    $tag = htmlTag($this->actingAs($user)->get(route('dashboard'))->assertOk());

    expect($tag)
        ->toContain('class="cf"')
        ->toContain('data-theme="dark"')
        ->not->toContain('data-theme-auto');
});

test('the saved user theme wins over the cookie', function () {
    $user = User::factory()->create(['theme' => Theme::Light]);

    $response = $this->actingAs($user)
        ->withUnencryptedCookie(Theme::COOKIE, 'dark')
        ->get(route('dashboard'));

    expect(htmlTag($response))->toContain('data-theme="light"');
});

test('guests get the theme from the cookie', function () {
    $response = $this->withUnencryptedCookie(Theme::COOKIE, 'dark')->get(route('login'));

    expect(htmlTag($response))
        ->toContain('data-theme="dark"')
        ->not->toContain('data-theme-auto');
});

test('without a saved theme the head script follows the OS preference', function () {
    expect(htmlTag($this->get(route('login'))))
        ->toContain('data-theme="light"')
        ->toContain('data-theme-auto');
});
