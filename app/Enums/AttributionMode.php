<?php

declare(strict_types=1);

namespace App\Enums;

/** How income is attributed for profit sharing (spec §4.4). */
enum AttributionMode: string
{
    case PerPartner = 'per_partner';
    case JvPooled = 'jv_pooled';
}
