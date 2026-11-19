<?php

declare(strict_types=1);

use App\Http\Responses\FailedTwoFactorSignIn;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Auth\TwoFactorCodes;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/** A user with a real TOTP secret, signed in up to the code step. */
function challengedUser(object $test): array
{
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $user = User::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['ABCD-EFGH', 'JKLM-NPQR'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $test->post(route('login'), ['email' => $user->email, 'password' => 'password']);

    return [$user, $google2fa->getCurrentOtp($secret)];
}

test('the challenge needs a pending sign-in', function () {
    $this->get(route('two-factor.login'))->assertRedirect(route('login'));
});

test('the challenge shows whose code it wants', function () {
    [$user] = challengedUser($this);

    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/two-factor-challenge')
            ->where('email', $user->email));
});

test('a valid code signs in; ticking trust remembers the device for 30 days', function () {
    [$user, $code] = challengedUser($this);

    $response = $this->post(route('two-factor.login.store'), ['code' => $code, 'trust_device' => '1']);

    $response->assertRedirect(route('home', absolute: false))
        ->assertCookie(TrustedDevice::COOKIE);
    $this->assertAuthenticatedAs($user);

    $device = $user->trustedDevices()->sole();
    expect($device->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();
});

test('without trust no device is remembered', function () {
    [$user, $code] = challengedUser($this);

    $this->post(route('two-factor.login.store'), ['code' => $code]);

    $this->assertAuthenticatedAs($user);
    expect($user->trustedDevices()->count())->toBe(0);
});

test('a recovery code works once', function () {
    [$user] = challengedUser($this);

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'ABCD-EFGH']);

    $this->assertAuthenticatedAs($user);
    expect(json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true))->not->toContain('ABCD-EFGH');
});

test('a wrong code keeps the user out', function () {
    challengedUser($this);

    $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('a wrong code says so', function () {
    [, $code] = challengedUser($this);
    $wrong = $code === '123456' ? '654321' : '123456';

    $this->post(route('two-factor.login.store'), ['code' => $wrong])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHasErrors(['code' => FailedTwoFactorSignIn::WRONG_CODE]);
});

test('the same code sent twice (sixth digit, then Enter) is reported as just used, not as wrong (D142)', function () {
    [$user, $code] = challengedUser($this);

    // The first post already spent this code: Fortify remembers it for its window.
    cache()->put('fortify.2fa_codes.'.md5($code), (new Google2FA)->getTimestamp(), 60);

    $this->post(route('two-factor.login.store'), ['code' => $code])
        ->assertSessionHasErrors(['code' => TwoFactorCodes::REPLAY_MESSAGE]);

    $this->assertGuest();
});

test('a code sent after the pending sign-in expired goes back to the password step', function () {
    $this->post(route('two-factor.login.store'), ['code' => '123456'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => FailedTwoFactorSignIn::EXPIRED]);
});
