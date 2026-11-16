<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * 2FA recovery codes as on Profile.dc.html (spec §17): ten codes like "K7QM-3XRP", from
 * an alphabet without look-alikes (no I, O, 0 or 1) so they can be read off paper.
 */
final class RecoveryCodes
{
    public const int COUNT = 10;

    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function one(): string
    {
        $pick = fn (): string => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        $half = fn (): string => $pick().$pick().$pick().$pick();

        return $half().'-'.$half();
    }

    /** @return list<string> */
    public static function set(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $codes[self::one()] = true;
        }

        return array_keys($codes);
    }
}
