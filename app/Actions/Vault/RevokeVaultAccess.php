<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultAuditAction;
use App\Models\User;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use Illuminate\Support\Facades\DB;

/**
 * Take a grant away (spec §14.2): removed from the share drawer, ended by its "Access
 * ends" date (the scheduled expiry) or because the person left the company. Audited as
 * Unshared with the reason.
 */
final readonly class RevokeVaultAccess
{
    public function __construct(private VaultAudit $audit, private VaultAccess $access) {}

    /** @param  'removed'|'expired'|'left'  $reason */
    public function handle(?User $by, VaultGrant $grant, string $reason = 'removed'): void
    {
        DB::transaction(function () use ($by, $grant, $reason): void {
            $target = $grant->vault_item_id !== null
                ? VaultItem::query()->withTrashed()->find($grant->vault_item_id)
                : $grant->vault;
            $to = $grant->user;

            if ($target !== null) {
                $this->audit->record(VaultAuditAction::Unshared, $by, $target, null, [
                    'to' => $to->id,
                    'to_name' => $to->name,
                    'level' => $grant->level->value,
                    'reason' => $reason,
                ], $grant->company_id);

                activity('vault')->performedOn($target)->causedBy($by)
                    ->withProperties(['user_id' => $to->id, 'reason' => $reason])
                    ->log('Vault access removed');
            }

            $grant->delete();
            $this->access->flush();
        });
    }
}
