<?php

declare(strict_types=1);

namespace App\Enums;

/** Shared vaults are reached through grants; a personal vault is its owner's alone (D68). */
enum VaultType: string
{
    case Shared = 'shared';
    case Personal = 'personal';
}
