<?php

declare(strict_types=1);

namespace App\Enums;

/** Spec §14.1: an item's environment. */
enum VaultEnvironment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
