<?php

declare(strict_types=1);

namespace App\Services\Vault;

use SensitiveParameter;

/**
 * Password strength on zxcvbn's 0–4 scale. The add/edit form sends the score zxcvbn gave
 * in the browser; this rough estimate is the server's fallback when there is none (seeds,
 * imports) (D70).
 */
final class PasswordStrength
{
    private const array COMMON = [
        'password', 'passw0rd', 'qwerty', 'letmein', 'welcome', 'admin', 'iloveyou', 'monkey', 'dragon', 'abc123',
        'changeme', 'secret', 'login', 'master', 'football', 'baseball', 'sunshine', 'princess', 'india', 'mumbai',
    ];

    public static function estimate(#[SensitiveParameter] string $password): int
    {
        $length = mb_strlen($password);

        if ($length === 0) {
            return 0;
        }

        $pool = 0;
        $pool += preg_match('/[a-z]/', $password) ? 26 : 0;
        $pool += preg_match('/[A-Z]/', $password) ? 26 : 0;
        $pool += preg_match('/\d/', $password) ? 10 : 0;
        $pool += preg_match('/[^a-zA-Z\d\s]/', $password) ? 15 : 0;
        $pool += preg_match('/\s/', $password) ? 1 : 0;
        $bits = $length * log(max($pool, 2), 2);

        $lower = mb_strtolower($password);

        // A word (or a common password) plus a few digits or a symbol: "saarthi2024", "Kiran@2025".
        if (preg_match('/^[a-z]+[^a-z0-9]?\d{0,4}[^a-z0-9]?$/i', $password) === 1) {
            $bits = min($bits, 34);
        }

        foreach (self::COMMON as $word) {
            if (str_contains($lower, $word)) {
                $bits = min($bits, 20);
            }
        }

        // One character repeated, or a straight run ("aaaaaa", "123456").
        if (preg_match('/^(.)\1+$/', $password) === 1 || str_contains('abcdefghijklmnopqrstuvwxyz0123456789', $lower)) {
            $bits = min($bits, 10);
        }

        return match (true) {
            $bits < 28 => 0,
            $bits < 36 => 1,
            $bits < 60 => 2,
            $bits < 80 => 3,
            default => 4,
        };
    }

    /** "Weak", "Fair", "Strong", "Very strong" (the generator's labels in VaultAddLogin.dc.html). */
    public static function label(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score <= 1 => 'Weak',
            $score === 2 => 'Fair',
            $score === 3 => 'Strong',
            default => 'Very strong',
        };
    }
}
