<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is stored as integer paise (CLAUDE.md). Mirrors parseRupeesToPaise in
 * resources/js/lib/format.ts for amounts typed in rupees.
 */
final class Money
{
    /** The largest amount accepted from a form: ₹1,000 crore. */
    public const int MAX_PAISE = 1_000_00_00_000_00;

    /** "2,50,000" / "₹1,234.5" / "0" → paise; null when it isn't a non-negative amount with ≤ 2 decimals. */
    public static function parseRupees(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[\s,₹]/u', '', (string) $value) ?? '';

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $clean) !== 1) {
            return null;
        }

        [$rupees, $paise] = array_pad(explode('.', $clean, 2), 2, '');
        $amount = (int) $rupees * 100 + (int) str_pad($paise, 2, '0');

        return $amount <= self::MAX_PAISE ? $amount : null;
    }
}
