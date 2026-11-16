<?php

declare(strict_types=1);

namespace App\Services\Vault;

use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * Item one-time codes (spec §14.3): the server computes the current code from the stored
 * setup key; the key itself never leaves. Keys are base32 (A–Z, 2–7); a pasted
 * otpauth:// link works too.
 */
final class Totp
{
    public const int PERIOD = 30;

    /** "JBSW Y3DP EHPK 3PXP" or an otpauth:// URI → "JBSWY3DPEHPK3PXP", or null when it isn't a key. */
    public static function normalise(#[SensitiveParameter] ?string $input): ?string
    {
        $value = trim((string) $input);

        if ($value === '') {
            return null;
        }

        if (str_starts_with(strtolower($value), 'otpauth://')) {
            parse_str((string) parse_url($value, PHP_URL_QUERY), $query);
            $value = is_string($query['secret'] ?? null) ? $query['secret'] : '';
        }

        $key = strtoupper((string) preg_replace('/[\s-]+/', '', $value));
        $key = rtrim($key, '=');

        return preg_match('/^[A-Z2-7]{10,128}$/', $key) === 1 ? $key : null;
    }

    /** @return array{code: string, secondsLeft: int, period: int} */
    public static function now(#[SensitiveParameter] string $key): array
    {
        $google = new Google2FA;
        $google->setKeyRegeneration(self::PERIOD);
        $google->setEnforceGoogleAuthenticatorCompatibility(false);
        $timestamp = now()->getTimestamp();

        return [
            'code' => $google->oathTotp($key, (int) floor($timestamp / self::PERIOD)),
            'secondsLeft' => self::PERIOD - ($timestamp % self::PERIOD),
            'period' => self::PERIOD,
        ];
    }
}
