<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Auth\RecoveryCodes;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes as FortifyGenerate;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Fortify;

/** "Make new recovery codes": ten fresh codes (spec §17); the old ones stop working. */
class GenerateNewRecoveryCodes extends FortifyGenerate
{
    public function __invoke($user)
    {
        $user->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt((string) json_encode(RecoveryCodes::set())),
        ])->save();

        RecoveryCodesGenerated::dispatch($user);
    }
}
