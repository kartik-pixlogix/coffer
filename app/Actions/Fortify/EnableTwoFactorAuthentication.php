<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Auth\RecoveryCodes;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnable;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

/** Starts 2FA setup: a new secret and ten recovery codes (spec §17), unconfirmed until a code. */
class EnableTwoFactorAuthentication extends FortifyEnable
{
    public function __invoke($user, $force = false)
    {
        if (empty($user->two_factor_secret) || $force === true) {
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey()),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt((string) json_encode(RecoveryCodes::set())),
                'two_factor_confirmed_at' => null,
            ])->save();

            TwoFactorAuthenticationEnabled::dispatch($user);
        }
    }
}
