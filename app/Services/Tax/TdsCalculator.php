<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Enums\TdsSection;

/** TDS arithmetic (D160): on the value before GST, rounded to the paisa. */
final class TdsCalculator
{
    /** The TDS on a taxable value, or null for salary (worked out per person). */
    public static function amount(int $taxable, TdsSection $section): ?int
    {
        $rate = $section->rateBps();

        return $rate === null ? null : (int) round($taxable * $rate / 10000);
    }
}
