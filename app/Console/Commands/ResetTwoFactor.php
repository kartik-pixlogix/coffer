<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Turns 2FA off for one user from the server console: for an owner whose authenticator and
 * recovery codes no longer match (lost phone, or a database reset since they set it up) and
 * who has no other Admin to reset it from Settings › Members. They sign in with the password
 * and set 2FA up again from Profile.
 */
final class ResetTwoFactor extends Command
{
    protected $signature = 'coffer:reset-2fa {email : The user’s email address}';

    protected $description = 'Turn off 2FA for a user so they can sign in and set it up again';

    public function handle(): int
    {
        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->components->error('No user with that email.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $user->trustedDevices()->delete();

        activity('security')->performedOn($user)->log('reset two-factor authentication from the console');

        $this->components->info("2FA is off for {$user->email}. Sign in with the password, then set it up again from Profile.");

        return self::SUCCESS;
    }
}
