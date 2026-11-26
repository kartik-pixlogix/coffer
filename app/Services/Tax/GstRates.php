<?php

declare(strict_types=1);

namespace App\Services\Tax;

/**
 * GST rates in basis points (D159). From 22 Sep 2025 the slabs are 5%, 18% and 40% (with 0%,
 * 0.25% and 3% for special goods); 12% and 28% stay selectable for bills dated before then.
 * [VERIFY] with the CA.
 */
final class GstRates
{
    /** @var array<int, string> rate in bps → label */
    public const array ALL = [
        0 => '0% (nil or exempt)',
        25 => '0.25%',
        300 => '3%',
        500 => '5%',
        1200 => '12% (before 22 Sep 2025)',
        1800 => '18%',
        2800 => '28% (before 22 Sep 2025)',
        4000 => '40%',
    ];

    public static function exists(int $bps): bool
    {
        return isset(self::ALL[$bps]);
    }

    /** @return list<int> */
    public static function values(): array
    {
        return array_keys(self::ALL);
    }

    /** @return list<array{value: int, label: string}> */
    public static function options(): array
    {
        $options = [];

        foreach (self::ALL as $bps => $label) {
            $options[] = ['value' => $bps, 'label' => $label];
        }

        return $options;
    }
}
