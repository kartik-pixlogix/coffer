<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\User;
use App\Support\Auth\IntendedUrl;
use PragmaRX\Google2FA\Google2FA;

/*
| G10, G13 (D113): sign-in goes back to the page asked for, never to an export or file,
| and otherwise to /home, which lands each role on its first permitted page.
*/

test('an export URL is never the place sign-in returns to', function (string $route) {
    $user = userWithRole(CompanyRole::Admin);

    $this->get(route($route))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home'));
})->with(['expenses.export', 'income.export', 'capital-accounts.export']);

test('a page asked for while signed out is kept', function () {
    $user = userWithRole(CompanyRole::Admin);

    $this->get(route('team.index'))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('team.index'));
});

test('a Member’s first sign-in lands on Timesheets, never the dashboard', function () {
    $member = userWithRole(CompanyRole::Member);

    $this->post(route('login.store'), ['email' => $member->email, 'password' => 'password'])->assertRedirect(route('home'));
    $this->get(route('home'))->assertRedirect(route('timesheets.index'));
});

test('the 2FA step uses the same rule', function () {
    $secret = (new Google2FA)->generateSecretKey();
    $user = userWithRole(CompanyRole::Admin, attributes: [
        'two_factor_secret' => encrypt($secret), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now(),
    ]);

    $this->get(route('expenses.export'));
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect(route('home'));
});

test('IntendedUrl tells pages from files and JSON', function () {
    expect(IntendedUrl::isPage(url('/expenses')))->toBeTrue()
        ->and(IntendedUrl::isPage(url('/expenses/export')))->toBeFalse()
        ->and(IntendedUrl::isPage(url('/no-such-page')))->toBeFalse()
        ->and(IntendedUrl::isPage('https://evil.example/expenses'))->toBeFalse();

    expect(User::query()->count())->toBe(0);
});
