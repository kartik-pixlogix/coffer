<?php

declare(strict_types=1);

namespace App\Notifications\Vault;

use App\Models\VaultItem;
use App\Services\Vault\ItemTemplates;

/** A credential expires in 30, 7 or 1 days (spec §14.4 "Expiry alerts", §19). */
final class VaultItemExpiring extends VaultNotification
{
    public static function for(VaultItem $item, int $days): self
    {
        $when = $days === 1 ? 'tomorrow' : "in {$days} days";
        $label = ItemTemplates::expiryLabel($item->type);
        $verb = $label === 'Expires on' ? 'expires' : 'renews';

        return new self(
            $item->company_id,
            "{$item->name} {$verb} {$when}",
            "{$label} ".($item->expires_at?->format('j M Y') ?? '')." · {$item->vault->name} vault. Renew it, then update the date in the vault.",
            route('vault.index', ['item' => $item->id]),
        );
    }

    public function event(): string
    {
        return 'vault.expiring';
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function tone(): string
    {
        return 'warn';
    }

    public function linkLabel(): string
    {
        return 'Open in vault';
    }
}
