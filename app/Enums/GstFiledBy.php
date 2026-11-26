<?php

declare(strict_types=1);

namespace App\Enums;

/** Who files the company's GST and TDS returns (D159). */
enum GstFiledBy: string
{
    case InHouse = 'in_house';
    case Ca = 'ca';

    public function label(): string
    {
        return match ($this) {
            self::InHouse => 'We file them ourselves',
            self::Ca => 'Our CA files them',
        };
    }
}
