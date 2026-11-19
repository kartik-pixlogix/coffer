<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\NumberFormat;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/** A row in the database sessions table for `$user`. */
function sessionRow(User $user, string $id, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => $agent,
        'payload' => '',
        'last_activity' => now()->subHours(2)->timestamp,
    ]);
}

test('guests are sent to sign in', function () {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

test('the profile page shows details, password, 2FA and sessions', function () {
    $user = userWithRole(CompanyRole::Partner, attributes: ['name' => 'Ishita Rao', 'password_changed_at' => '2026-08-02 10:00:00']);
    sessionRow($user, 'other-session', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/604.1');

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/profile')
            ->where('profile.name', 'Ishita Rao')
            ->where('profile.numberFormat', 'indian')
            ->where('password.changedAt', '2026-08-02')
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.required', false)
            ->where('sessions', fn ($sessions) => collect($sessions)->contains(fn ($s) => $s['device'] === 'Safari on iPhone' && $s['mobile'] === true)));
});

test('profile details save, including time zone and number format', function () {
    $user = userWithRole();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Aarav M', 'email' => $user->email, 'timezone' => 'Asia/Dubai', 'number_format' => 'international'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Aarav M')
        ->and($user->timezone)->toBe('Asia/Dubai')
        ->and($user->number_format)->toBe(NumberFormat::International)
        ->and($user->email_verified_at)->not->toBeNull();
});

test('a new email needs confirming again', function () {
    Notification::fake();
    $user = userWithRole();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'NEW@vertexlabs.in', 'timezone' => 'Asia/Kolkata', 'number_format' => 'indian']);

    $user->refresh();
    expect($user->email)->toBe('new@vertexlabs.in')->and($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, QueuedVerifyEmail::class, fn ($n) => $n->queue === 'mail');
});

test('profile details are validated', function () {
    $user = userWithRole();
    User::factory()->create(['email' => 'taken@vertexlabs.in']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => '', 'email' => 'taken@vertexlabs.in', 'timezone' => 'Mars/Base', 'number_format' => 'roman'])
        ->assertSessionHasErrors(['name', 'email', 'timezone', 'number_format']);
});

test('a photo can be uploaded and removed', function () {
    Storage::fake('public');
    $user = userWithRole();

    $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])->assertSessionHasNoErrors();

    $path = $user->fresh()->avatar_path;
    Storage::disk('public')->assertExists($path);

    $this->delete(route('profile.avatar.destroy'));
    Storage::disk('public')->assertMissing($path);
    expect($user->fresh()->avatar_path)->toBeNull();
});

test('photos must be small images', function () {
    Storage::fake('public');

    $this->actingAs(userWithRole())
        ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')])
        ->assertSessionHasErrors('avatar');
});

test('changing the password signs out the other sessions', function () {
    $user = userWithRole();
    sessionRow($user, 'phone-session');

    $this->actingAs($user)
        ->put(route('profile.password.update'), ['current_password' => 'password', 'password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect(Hash::check('a brand new passphrase', $user->password))->toBeTrue()
        ->and($user->password_changed_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', 'phone-session')->exists())->toBeFalse();
});

test('the password change is validated', function (array $input, string $field) {
    $this->actingAs(userWithRole())
        ->put(route('profile.password.update'), $input)
        ->assertSessionHasErrors($field);
})->with([
    'wrong current' => [['current_password' => 'nope', 'password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase'], 'current_password'],
    'too short' => [['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'mismatch' => [['current_password' => 'password', 'password' => 'a brand new passphrase', 'password_confirmation' => 'another passphrase'], 'password'],
]);

test('one session or all the others can be signed out', function () {
    $user = userWithRole();
    sessionRow($user, 'laptop');
    sessionRow($user, 'tablet');
    $stranger = User::factory()->create();
    sessionRow($stranger, 'strangers');

    $this->actingAs($user)->delete(route('profile.sessions.destroy', hash('sha256', 'laptop')))->assertRedirect();
    expect(DB::table('sessions')->where('id', 'laptop')->exists())->toBeFalse();

    // Someone else's session can't be named.
    $this->delete(route('profile.sessions.destroy', hash('sha256', 'strangers')))->assertNotFound();

    $this->delete(route('profile.sessions.destroy-others'))->assertRedirect();
    expect(DB::table('sessions')->where('user_id', $user->id)->where('id', 'tablet')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'strangers')->exists())->toBeTrue();
});

test('trusted devices can be forgotten', function () {
    $user = userWithRole();
    $user->trustedDevices()->create(['token_hash' => str_repeat('a', 64), 'label' => 'Chrome on Windows', 'expires_at' => now()->addDays(TrustedDevice::DAYS)]);

    $this->actingAs($user)->delete(route('profile.trusted-devices.destroy'))->assertRedirect();

    expect($user->trustedDevices()->count())->toBe(0);
});

test('Admins can’t turn 2FA off; others can', function () {
    $admin = userWithRole(CompanyRole::Admin, attributes: ['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'), ['confirmation' => 'password'])
        ->assertSessionHasErrors(['two_factor' => 'You’re an Admin, so 2FA stays on.']);
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $partner = userWithRole(CompanyRole::Partner, attributes: ['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($partner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'), ['confirmation' => 'password'])
        ->assertSessionHasNoErrors();
    expect($partner->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('an Admin can still cancel a setup they never confirmed', function () {
    $admin = userWithRole(CompanyRole::Admin, attributes: ['two_factor_secret' => encrypt('SECRET'), 'two_factor_recovery_codes' => encrypt('[]')]);

    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertSessionHasNoErrors();

    expect($admin->fresh()->two_factor_secret)->toBeNull();
});

test('starting 2FA shows the QR, key and recovery codes', function () {
    $user = userWithRole(CompanyRole::Partner);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.pending', true)
            ->where('twoFactor.qrSvg', fn ($svg) => str_starts_with($svg, '<svg'))
            ->where('twoFactor.secret', fn ($secret) => strlen($secret) >= 16)
            ->has('twoFactor.recoveryCodes', 10)
            ->where('twoFactor.recoveryCodes.0', fn ($code) => preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code) === 1));
});

test('replacing the authenticator issues a new secret', function () {
    $user = userWithRole(attributes: ['two_factor_secret' => encrypt('OLDSECRETOLDSECR'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('profile.two-factor.replace'), ['confirmation' => 'password'])
        ->assertRedirect();

    expect(decrypt($user->fresh()->two_factor_secret))->not->toBe('OLDSECRETOLDSECR');
});

test('confirming with a code from the app turns 2FA on', function () {
    $user = userWithRole(CompanyRole::Partner);
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get(route('profile.two-factor.start'))->assertRedirect(route('profile.edit'));

    $secret = decrypt($user->fresh()->two_factor_secret);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasNoErrors();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('twoFactor.enabled', true)->where('twoFactor.codesLeft', 10)->where('twoFactor.recoveryCodes', null));
});
