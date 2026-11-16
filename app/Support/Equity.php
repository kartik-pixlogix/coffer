<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Equity in basis points of a percent (10000 = 100.00%), so shares are exact integers
 * with two decimals. Mirrors resources/js/lib/equity.ts, which the wizard and the
 * partner settings use for live validation (spec §3.1, §16).
 */
final class Equity
{
    public const int WHOLE = 10000;

    /** "60", "60.5", "60.00 %" → 6000 / 6050 / 6000; null when it isn't a 0–100 number with ≤ 2 decimals. */
    public static function parse(string|int|float|null $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9.]/', '', (string) $value) ?? '';

        if ($clean === '' || preg_match('/^\d{1,3}(\.\d{0,2})?$/', $clean) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');
        $bps = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $bps <= self::WHOLE ? $bps : null;
    }

    /** 6000 → "60.00" */
    public static function format(int $bps): string
    {
        return intdiv($bps, 100).'.'.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param list<int> $shares */
    public static function isWhole(array $shares): bool
    {
        return array_sum($shares) === self::WHOLE;
    }

    /**
     * "Even split" (spec §3.1): equal shares, the rounding remainder on the first row.
     *
     * @return list<int>
     */
    public static function evenSplit(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $each = intdiv(self::WHOLE, $count);
        $shares = array_fill(0, $count, $each);
        $shares[0] += self::WHOLE - $each * $count;

        return $shares;
    }

    /**
     * The fix in words: "Equity adds up to 95.00%. Add 5.00% so it totals 100%."
     *
     * @param  string  $subject  e.g. "Equity", "Vertex Labs partners"
     * @param  string  $target  e.g. "it totals 100%", "this side totals 100%"
     */
    public static function gapMessage(int $total, string $subject = 'Equity', string $target = 'it totals 100%'): string
    {
        $gap = self::WHOLE - $total;
        $verb = $gap > 0 ? 'Add' : 'Remove';

        return "{$subject} add".($subject === 'Equity' ? 's' : '').' up to '.self::format($total)."%. {$verb} ".self::format(abs($gap))."% so {$target}.";
    }
}
