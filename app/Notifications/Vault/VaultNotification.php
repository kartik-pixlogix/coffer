<?php

declare(strict_types=1);

namespace App\Notifications\Vault;

use App\Notifications\CofferNotification;

/**
 * A vault notification (spec §19 "Vault" events, D72): the Security category. Built from
 * names and labels only: never a secret value, and the queued payload holds nothing but
 * these strings.
 */
abstract class VaultNotification extends CofferNotification
{
    public function linkLabel(): string
    {
        return 'Open in vault';
    }
}
