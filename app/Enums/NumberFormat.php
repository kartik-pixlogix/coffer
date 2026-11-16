<?php

declare(strict_types=1);

namespace App\Enums;

/** Per-user digit grouping (spec §17): Indian 12,34,567 or International 1,234,567. */
enum NumberFormat: string
{
    case Indian = 'indian';
    case International = 'international';
}
