<?php

declare(strict_types=1);

namespace App\Notifications\Vault;

use App\Models\VaultAccessRequest;

/** Someone asked for access to something you manage (spec §19 "vault access requested"). */
final class VaultAccessRequested extends VaultNotification
{
    public static function for(VaultAccessRequest $request): self
    {
        return new self(
            $request->company_id,
            "{$request->user->name} asked for {$request->level->label()} access",
            "{$request->targetName()} · {$request->duration->span()} · “{$request->reason}”",
            route('vault.requests'),
        );
    }

    public function event(): string
    {
        return 'vault.access_requested';
    }

    public function icon(): string
    {
        return 'user-plus';
    }

    public function tone(): string
    {
        return 'plain';
    }

    public function linkLabel(): string
    {
        return 'Review requests';
    }
}
