<?php

declare(strict_types=1);

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the sign-in page renders', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canResetPassword', true)
            ->has('googleEnabled'));
});

test('users can sign in and land on their first page', function () {
    $user = userWithRole();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('users with two-factor authentication get the code challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('a wrong password says so on both fields', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors([
            'email' => 'That email and password don’t match.',
            'password' => 'Check your password and try again.',
        ]);

    $this->assertGuest();
});

test('from the third failure the error counts down to the lock', function () {
    $user = User::factory()->create();
    $attempt = fn () => $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $attempt();
    $attempt();

    $attempt()->assertSessionHasErrors(['email' => 'That email and password don’t match. 2 tries left before a 15-minute lock.']);
    $attempt()->assertSessionHasErrors(['email' => 'That email and password don’t match. 1 try left before a 15-minute lock.']);
    $attempt()->assertSessionHasErrors(['email' => 'That email and password don’t match. Sign-in is locked for 15 minutes.']);
});

test('after five failures sign-in is locked for 15 minutes, even with the right password', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        RateLimiter::hit(Str::lower($user->email).'|127.0.0.1', 15 * 60);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Too many tries, so sign-in is locked. Try again in 15 minutes, or reset your password.']);

    $this->assertGuest();

    $this->travel(16)->minutes();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('a trusted browser skips the 2FA code until the device expires', function () {
    $user = User::factory()->withTwoFactor()->create();
    $token = Str::random(64);
    $user->trustedDevices()->create([
        'token_hash' => hash('sha256', $token),
        'label' => 'Chrome on Windows',
        'expires_at' => now()->addDays(TrustedDevice::DAYS),
    ]);

    $cookie = [TrustedDevice::COOKIE => $user->id.'|'.$token];

    $this->withCookies($cookie)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home', absolute: false));
    $this->assertAuthenticatedAs($user);

    auth()->logout();
    $this->travel(TrustedDevice::DAYS + 1)->days();

    $this->withCookies($cookie)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
});

test('another user’s trusted-device cookie doesn’t skip the code', function () {
    $owner = User::factory()->create();
    $token = Str::random(64);
    $owner->trustedDevices()->create(['token_hash' => hash('sha256', $token), 'label' => 'x', 'expires_at' => now()->addDay()]);
    $user = User::factory()->withTwoFactor()->create();

    $this->withCookies([TrustedDevice::COOKIE => $user->id.'|'.$token])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
});

test('users can sign out', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('welcome'));

    $this->assertGuest();
});

test('the Google buttons only show when Google sign-in is configured (R3)', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleEnabled', false));
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('googleEnabled', false));

    config(['services.google.client_id' => 'id.apps.googleusercontent.com', 'services.google.client_secret' => 'secret']);
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
});
