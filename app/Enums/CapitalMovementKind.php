<?php

declare(strict_types=1);

namespace App\Enums;

/** Spec §11: money a partner puts in (contribution) or takes out (drawing). */
enum CapitalMovementKind: string
{
    case Contribution = 'contribution';
    case Drawing = 'drawing';
}
