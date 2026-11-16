<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
});

function googleReturns(string $email, string $name = 'Kabir Shah', bool $verified = true): void
{
    $google = (new GoogleUser)->setRaw(['email_verified' => $verified])->map([
        'id' => '1234',
        'name' => $name,
        'email' => $email,
    ]);

    Socialite::shouldReceive('driver->user')->andReturn($google);
}

test('the redirect refuses politely when Google isn’t configured', function () {
    config(['services.google.client_id' => null]);

    $this->get(route('google.redirect'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Google sign-in isn’t set up yet. Use your email and password.']);
});

test('Google signs in an existing user by verified email', function () {
    $user = userWithRole(attributes: ['email' => 'kabir@bluefininfotech.in']);
    googleReturns('Kabir@BluefinInfotech.in');

    $this->withSession(['google.intent' => 'login'])
        ->get(route('google.callback'))
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
});

test('Google still asks users with 2FA for their code', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'kabir@bluefininfotech.in']);
    googleReturns('kabir@bluefininfotech.in');

    $this->get(route('google.callback'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('Google sign-in without an account says how to get one', function () {
    googleReturns('nobody@example.com');

    $this->withSession(['google.intent' => 'login'])
        ->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an unverified Google email is refused', function () {
    googleReturns('kabir@bluefininfotech.in', verified: false);

    $this->get(route('google.callback'))->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('Google sign-up creates a verified user and their workspace', function () {
    googleReturns('meera@bluefininfotech.in', 'Meera Iyer');

    $this->withSession(['google.intent' => 'signup'])
        ->get(route('google.callback'))
        ->assertRedirect(route('home'));

    $user = User::query()->where('email', 'meera@bluefininfotech.in')->sole();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(Workspace::query()->where('owner_id', $user->id)->value('name'))->toBe('Meera’s workspace');
});

test('Google can accept an invite for the same email', function () {
    $company = Company::factory()->create();
    $invite = Invite::factory()->for($company)->create(['email' => 'kabir@bluefininfotech.in', 'role' => CompanyRole::Partner]);
    googleReturns('kabir@bluefininfotech.in');

    $this->withSession(['google.intent' => 'invite', 'google.invite' => $invite->token()])
        ->get(route('google.callback'))
        ->assertRedirect(route('home'));

    $user = User::query()->where('email', 'kabir@bluefininfotech.in')->sole();
    expect($company->roleOf($user))->toBe(CompanyRole::Partner);
});

test('Google refuses an invite meant for another email', function () {
    $invite = Invite::factory()->create(['email' => 'kabir@bluefininfotech.in']);
    googleReturns('someone@else.in');

    $this->withSession(['google.intent' => 'invite', 'google.invite' => $invite->token()])
        ->get(route('google.callback'))
        ->assertRedirect(route('invites.show', $invite->token()))
        ->assertSessionHasErrors(['email' => 'This invite is for kabir@bluefininfotech.in. Sign in to Google with that address.']);

    expect(User::query()->where('email', 'someone@else.in')->exists())->toBeFalse();
});
