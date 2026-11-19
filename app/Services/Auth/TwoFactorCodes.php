<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

/**
 * Checks a 2FA code (or a recovery code) for vault unlock, step-up, export and
 * re-authentication (V3, D108). Fortify remembers each accepted code for its window so it
 * can't be used twice; a code just spent on sign-in is reported as `Replayed` (the user
 * should wait for the next one) rather than as a wrong code, and doesn't count as a failure.
 */
final readonly class TwoFactorCodes
{
    public const string VALID = 'valid';

    public const string REPLAYED = 'replayed';

    public const string INVALID = 'invalid';

    public const string REPLAY_MESSAGE = 'That code was just used. Wait for the next one.';

    public function __construct(private TwoFactorAuthenticationProvider $provider, private Google2FA $engine) {}

    /** @return self::VALID|self::REPLAYED|self::INVALID */
    public function check(User $user, string $code, bool $allowRecovery = true): string
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code === '' || ! $user->hasTwoFactorEnabled()) {
            return self::INVALID;
        }

        if (preg_match('/^\d{6}$/', $code) === 1) {
            $secret = (string) decrypt((string) $user->two_factor_secret);

            if ($this->provider->verify($secret, $code)) {
                return self::VALID;
            }

            return $this->wasJustUsed($user, $code) ? self::REPLAYED : self::INVALID;
        }

        if ($allowRecovery && in_array($code, $user->recoveryCodes(), true)) {
            $user->replaceRecoveryCode($code);

            return self::VALID;
        }

        return self::INVALID;
    }

    /** Right for this moment, but Fortify has already accepted it once. */
    public function wasJustUsed(User $user, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1 || empty($user->two_factor_secret)) {
            return false;
        }

        return cache()->has('fortify.2fa_codes.'.md5($code))
            && $this->engine->verifyKey((string) decrypt((string) $user->two_factor_secret), $code);
    }
}
