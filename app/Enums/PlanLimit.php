<?php

declare(strict_types=1);

namespace App\Enums;

/** The soft limits a plan sets (spec §20, D82). Storage is a meter only. */
enum PlanLimit: string
{
    case Companies = 'companies';
    case Users = 'users';
    case VaultItems = 'vault_items';
    case Storage = 'storage';

    public function column(): string
    {
        return match ($this) {
            self::Companies => 'max_companies',
            self::Users => 'max_users',
            self::VaultItems => 'max_vault_items',
            self::Storage => 'max_storage_mb',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Companies => 'Companies',
            self::Users => 'Users',
            self::VaultItems => 'Vault items',
            self::Storage => 'Storage',
        };
    }

    /** "companies", "users", "vault items" — for "You've used all 5 companies". */
    public function noun(int $n): string
    {
        return match ($this) {
            self::Companies => $n === 1 ? 'company' : 'companies',
            self::Users => $n === 1 ? 'user' : 'users',
            self::VaultItems => $n === 1 ? 'vault item' : 'vault items',
            self::Storage => 'MB of storage',
        };
    }
}
