<?php

declare(strict_types=1);

namespace App\Enums;

/** A company's GST registration (D159). Composition dealers charge no GST and take no input credit. */
enum GstRegistration: string
{
    case Unregistered = 'unregistered';
    case Regular = 'regular';
    case Composition = 'composition';

    public function label(): string
    {
        return match ($this) {
            self::Unregistered => 'Not registered',
            self::Regular => 'Regular',
            self::Composition => 'Composition scheme',
        };
    }

    /** Charges GST on its sales and claims input tax credit. */
    public function isRegular(): bool
    {
        return $this === self::Regular;
    }
}
