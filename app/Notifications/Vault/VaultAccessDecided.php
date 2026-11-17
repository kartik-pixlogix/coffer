<?php

declare(strict_types=1);

namespace App\Notifications\Vault;

use App\Enums\VaultRequestStatus;
use App\Models\VaultAccessRequest;

/** Your access request was approved or denied (spec §14.2 "the requester is notified"). */
final class VaultAccessDecided extends VaultNotification
{
    public static function for(VaultAccessRequest $request): self
    {
        $approved = $request->status === VaultRequestStatus::Approved;
        $by = $request->decider->name ?? 'A Manager';
        $note = $request->decision_note !== null ? " · “{$request->decision_note}”" : '';

        return new self(
            $request->company_id,
            $approved ? "You can now {$request->level->value} {$request->targetName()}" : "Your request for {$request->targetName()} was denied",
            ($approved ? "Approved by {$by}, {$request->duration->span()}." : "Denied by {$by}.").$note,
            $request->vault_item_id !== null ? route('vault.index', ['item' => $request->vault_item_id]) : route('vault.index'),
        );
    }

    public function event(): string
    {
        return 'vault.access_decided';
    }

    public function icon(): string
    {
        return 'key-round';
    }

    public function tone(): string
    {
        return 'plain';
    }

    public function linkLabel(): string
    {
        return 'Open in vault';
    }
}
