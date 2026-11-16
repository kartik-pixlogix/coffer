<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Spec §14.2 permission levels, lowest first: View (metadata only), Use (reveal, copy,
 * one-time codes), Edit, Manage (share, delete).
 */
enum VaultAccessLevel: string
{
    case View = 'view';
    case Use = 'use';
    case Edit = 'edit';
    case Manage = 'manage';

    public function rank(): int
    {
        return match ($this) {
            self::View => 1,
            self::Use => 2,
            self::Edit => 3,
            self::Manage => 4,
        };
    }

    public function atLeast(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function lower(self $a, self $b): self
    {
        return $a->rank() <= $b->rank() ? $a : $b;
    }

    public static function higher(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
