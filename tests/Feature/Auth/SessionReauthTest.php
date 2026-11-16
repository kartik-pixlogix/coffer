<?php

declare(strict_types=1);

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

test('an Inertia request after the session ended is told so instead of redirected (D30)', function () {
    $this->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertUnauthorized()
        ->assertHeader('X-Coffer-Session', 'expired');
});

test('a normal page load still goes to sign in', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('the dialog gets a fresh CSRF token', function () {
    $this->getJson(route('session.token'))->assertOk()->assertJsonStructure(['token']);
});

test('signing back in with the password restores the session', function () {
    $user = userWithRole(attributes: ['email' => 'aarav@vertexlabs.in']);

    $this->postJson(route('session.reauth'), ['email' => 'aarav@vertexlabs.in', 'password' => 'password'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->assertAuthenticatedAs($user);
});

test('a wrong password says so and counts toward the lock', function () {
    userWithRole(attributes: ['email' => 'aarav@vertexlabs.in']);

    $this->postJson(route('session.reauth'), ['email' => 'aarav@vertexlabs.in', 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'That password didn’t work. Try again or reset it.');

    $this->assertGuest();

    foreach (range(1, 4) as $_) {
        $this->postJson(route('session.reauth'), ['email' => 'aarav@vertexlabs.in', 'password' => 'nope']);
    }

    $this->postJson(route('session.reauth'), ['email' => 'aarav@vertexlabs.in', 'password' => 'password'])
        ->assertStatus(429);
});

test('with 2FA the dialog asks for the code next', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->create([
        'email' => 'kabir@bluefininfotech.in',
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['AAAA-BBBB'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->postJson(route('session.reauth'), ['email' => 'kabir@bluefininfotech.in', 'password' => 'password'])
        ->assertOk()
        ->assertJson(['twoFactor' => true]);
    $this->assertGuest();

    $this->postJson(route('session.reauth.code'), ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'That code didn’t match. Codes change every 30 seconds.');

    $this->postJson(route('session.reauth.code'), ['code' => $google2fa->getCurrentOtp($secret)])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->assertAuthenticatedAs($user);
});

test('the code step needs the password step first', function () {
    $this->postJson(route('session.reauth.code'), ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'Sign in with your password first.');
});
