<?php

declare(strict_types=1);

namespace App\Enums;

enum CompanyType: string
{
    case Single = 'single';
    case Jv = 'jv';

    public function label(): string
    {
        return $this === self::Jv ? 'Joint Venture' : 'Single company';
    }

    public function defaultAttribution(): AttributionMode
    {
        return $this === self::Jv ? AttributionMode::JvPooled : AttributionMode::PerPartner;
    }
}
