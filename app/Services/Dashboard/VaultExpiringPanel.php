<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultHealth;

/**
 * "Credentials expiring soon" (spec §5, Main.dc.html, D64): the items the user can see that
 * expire within 30 days (or already have), soonest first, with the vault health score.
 * Hidden for users without vault access. Names and dates only.
 */
final readonly class VaultExpiringPanel implements DashboardVaultPanel
{
    public function __construct(private VaultAccess $access, private VaultHealth $health) {}

    public function for(Company $company, User $user): ?array
    {
        // P4 (D116): no vault card (or health link) for someone who can't open the vault, 2FA included.
        if ($this->access->ceiling($user) === null || ! $user->can('page.vault') || ! $user->hasTwoFactorEnabled()) {
            return null;
        }

        $items = $this->access->visibleItems($user);

        if ($items->isEmpty()) {
            return null;
        }

        $today = now()->startOfDay();
        $soon = array_values($items
            ->filter(fn (VaultItem $item) => $item->expires_at !== null && $item->expires_at->lte($today->addDays(VaultHealth::EXPIRING_DAYS)))
            ->sortBy(fn (VaultItem $item) => $item->expires_at?->toDateString())
            ->take(4)
            ->map(fn (VaultItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'initials' => $item->monogram(),
                'expiresOn' => (string) $item->expires_at?->toDateString(),
                'days' => max(0, (int) $today->diffInDays($item->expires_at, false)),
                'href' => route('vault.index', ['item' => $item->id]),
            ])
            ->all());

        return [
            'items' => $soon,
            'healthScore' => $this->health->summarise($items)['score'],
            'href' => route('vault.index'),
        ];
    }
}
