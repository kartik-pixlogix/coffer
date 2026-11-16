<?php

declare(strict_types=1);

namespace App\Enums;

enum CostType: string
{
    case Hourly = 'hourly';
    case FixedMonthly = 'fixed_monthly';

    public function label(): string
    {
        return $this === self::Hourly ? 'Hourly' : 'Fixed monthly';
    }
}
