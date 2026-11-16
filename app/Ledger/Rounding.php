<?php

declare(strict_types=1);

namespace App\Ledger;

use InvalidArgumentException;

/**
 * Splits an amount of paise by weights so the parts always add up to the amount exactly
 * (spec §4.4: largest-remainder rounding). Used for the opening balance, profit shares,
 * FY close and settlements.
 */
final class Rounding
{
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights  non-negative, in any unit (bps, bps × bps…)
     * @return array<TKey, int>
     */
    public static function largestRemainder(int $total, array $weights): array
    {
        $sum = array_sum($weights);

        if ($weights === [] || $sum <= 0) {
            if ($total === 0) {
                return array_map(fn () => 0, $weights);
            }

            throw new InvalidArgumentException('Cannot split a non-zero amount with no weights.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Weights must not be negative.');
            }
        }

        $sign = $total < 0 ? -1 : 1;
        $abs = abs($total);

        // floor(abs × w / sum) without overflowing: abs = q × sum + r.
        $quotient = intdiv($abs, $sum);
        $rest = $abs % $sum;

        $parts = [];
        $remainders = [];

        foreach ($weights as $key => $weight) {
            $parts[$key] = $quotient * $weight + intdiv($rest * $weight, $sum);
            $remainders[$key] = ($rest * $weight) % $sum;
        }

        $left = $abs - array_sum($parts);

        // Hand the leftover paise to the largest remainders; ties go to the earlier key.
        $order = array_keys($remainders);
        $position = array_flip($order);
        usort($order, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($position[$a] <=> $position[$b]));

        foreach (array_slice($order, 0, $left) as $key) {
            $parts[$key]++;
        }

        return array_map(fn (int $part) => $sign * $part, $parts);
    }
}
