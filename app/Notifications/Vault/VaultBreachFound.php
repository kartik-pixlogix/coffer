<?php

declare(strict_types=1);

namespace App\Notifications\Vault;

use App\Models\VaultItem;

/** An item's password showed up in a breach list (spec §19 "breach found"). */
final class VaultBreachFound extends VaultNotification
{
    public static function for(VaultItem $item): self
    {
        return new self(
            $item->company_id,
            "{$item->name} was found in a breach",
            "Its password is in a public breach list. Rotate it first · {$item->vault->name} vault.",
            route('vault.health', ['filter' => 'breached']),
        );
    }

    public function event(): string
    {
        return 'vault.breach_found';
    }

    public function icon(): string
    {
        return 'shield-alert';
    }
}
