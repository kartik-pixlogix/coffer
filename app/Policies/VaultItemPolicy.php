<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\VaultAccessLevel;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;

/**
 * Per-item vault permissions (spec §2.2, §14.2), all through VaultAccess: View to see the
 * item, Use to reveal / copy / get a code / download, Edit to change or restore it,
 * Manage to delete it (and, in part B, share it). Viewing needs 2FA on the account
 * (spec §14.3 "Users without 2FA enabled can't use the vault").
 */
final class VaultItemPolicy
{
    public function __construct(private readonly VaultAccess $access) {}

    public function view(User $user, VaultItem $item): bool
    {
        return $user->hasTwoFactorEnabled() && $this->access->can($user, $item, VaultAccessLevel::View);
    }

    public function reveal(User $user, VaultItem $item): bool
    {
        return $user->hasTwoFactorEnabled() && $this->access->can($user, $item, VaultAccessLevel::Use);
    }

    public function update(User $user, VaultItem $item): bool
    {
        return $user->hasTwoFactorEnabled() && $this->access->can($user, $item, VaultAccessLevel::Edit);
    }

    public function restore(User $user, VaultItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function delete(User $user, VaultItem $item): bool
    {
        return $user->hasTwoFactorEnabled() && $this->access->can($user, $item, VaultAccessLevel::Manage);
    }

    public function share(User $user, VaultItem $item): bool
    {
        return $this->delete($user, $item);
    }
}
