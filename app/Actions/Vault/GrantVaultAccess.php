<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Share a vault or one item with someone at View, Use, Edit or Manage, optionally until a
 * date (spec §14.2). One grant per person and target: sharing again changes it. Audited
 * as Shared; the role still caps what the grant can give (VaultAccess).
 */
final readonly class GrantVaultAccess
{
    public function __construct(private VaultAudit $audit, private VaultAccess $access) {}

    /** @param  'share'|'request'|'change'  $via */
    public function handle(?User $by, Vault|VaultItem $target, User $to, VaultAccessLevel $level, ?CarbonImmutable $endsAt, string $via = 'share'): VaultGrant
    {
        return DB::transaction(function () use ($by, $target, $to, $level, $endsAt, $via): VaultGrant {
            $column = $target instanceof VaultItem ? 'vault_item_id' : 'vault_id';

            /** @var VaultGrant $grant */
            $grant = VaultGrant::query()->firstOrNew([$column => $target->id, 'user_id' => $to->id]);
            $was = $grant->exists ? $grant->level : null;

            $grant->fill([
                'company_id' => $target->company_id,
                'level' => $level,
                'expires_at' => $endsAt,
                'granted_by' => $by?->id,
            ])->save();

            $this->audit->record(VaultAuditAction::Shared, $by, $target, null, [
                'to' => $to->id,
                'to_name' => $to->name,
                'level' => $level->value,
                'from_level' => $was?->value,
                'until' => $endsAt?->toDateString(),
                'via' => $via,
            ]);

            activity('vault')->performedOn($target)->causedBy($by)
                ->withProperties(['user_id' => $to->id, 'level' => $level->value, 'until' => $endsAt?->toDateString()])
                ->log($was === null ? 'Vault access shared' : 'Vault access changed');

            $this->access->flush();

            return $grant;
        });
    }
}
