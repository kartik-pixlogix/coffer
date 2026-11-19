<?php

declare(strict_types=1);

namespace App\Support;

use NumberFormatter;

/**
 * Money is stored as integer paise (CLAUDE.md). Mirrors parseRupeesToPaise in
 * resources/js/lib/format.ts for amounts typed in rupees.
 */
final class Money
{
    /** The largest amount accepted from a form: ₹1,000 crore. */
    public const int MAX_PAISE = 1_000_00_00_000_00;

    /** The largest amount a money field accepts (F1, D116): `money.max_paise`, never above MAX_PAISE. */
    public static function maxPaise(): int
    {
        return max(1, min(self::MAX_PAISE, (int) config('money.max_paise', self::MAX_PAISE)));
    }

    /** The message for a money field over maxPaise() (shows the cap in Indian format). */
    public static function maxMessage(): string
    {
        return 'That amount is too large. The most one entry can be is '.self::format(self::maxPaise()).'.';
    }

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

    /**
     * "₹12,34,567" / "₹1,234.50" / "−₹2,66,500" (CLAUDE.md: Indian grouping, a true minus).
     * Paise show only when there are any.
     */
    public static function format(int $paise): string
    {
        $abs = abs($paise);
        $formatter = new NumberFormatter('en_IN', NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $abs % 100 === 0 ? 0 : 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $abs % 100 === 0 ? 0 : 2);

        return ($paise < 0 ? '−₹' : '₹').$formatter->format($abs / 100);
    }

    /** "350000.00" for CSV exports: rupees with two decimals, no grouping. */
    public static function plain(int $paise): string
    {
        return ($paise < 0 ? '-' : '').intdiv(abs($paise), 100).'.'.str_pad((string) (abs($paise) % 100), 2, '0', STR_PAD_LEFT);
    }
}
