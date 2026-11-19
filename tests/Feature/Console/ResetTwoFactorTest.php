<?php

declare(strict_types=1);

use App\Models\User;

test('coffer:reset-2fa turns 2FA off so the password alone signs in', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['ABCD-EFGH'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->artisan('coffer:reset-2fa', ['email' => strtoupper($user->email)])->assertSuccessful();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

test('coffer:reset-2fa fails for an unknown email', function () {
    $this->artisan('coffer:reset-2fa', ['email' => 'nobody@example.com'])->assertFailed();
});
