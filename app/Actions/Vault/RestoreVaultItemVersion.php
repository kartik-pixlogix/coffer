<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultAuditAction;
use App\Models\User;
use App\Models\VaultItem;
use App\Models\VaultItemVersion;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\ItemVersions;
use App\Services\Vault\VaultAudit;
use Illuminate\Support\Facades\DB;

/**
 * Restore an old version (spec §14.4, audited): its metadata and secrets become the item's
 * again as a new version, so nothing in the history is lost. The item stays in its
 * current vault (moving vaults is an edit with its own permission check).
 */
final readonly class RestoreVaultItemVersion
{
    public function __construct(private ItemVersions $versions, private ItemSecrets $secrets, private VaultAudit $audit) {}

    public function handle(User $user, VaultItem $item, VaultItemVersion $version): VaultItem
    {
        return DB::transaction(function () use ($user, $item, $version): VaultItem {
            $snapshot = $this->versions->open($version);
            $attributes = $snapshot['attributes'];
            unset($attributes['vault_id']);

            $primary = ItemTemplates::primary($item->type);
            $before = $primary === null ? null : $this->secrets->get($item, $primary);

            $item->setRawAttributes([...$item->getAttributes(), ...$attributes]);
            $type = $item->type;
            $this->secrets->replaceAll($item, $snapshot['secrets'], fn (string $field) => ItemTemplates::isFingerprinted($type, $field));

            $primary = ItemTemplates::primary($type);
            $after = $primary === null ? null : ($snapshot['secrets'][$primary] ?? null);

            if ($before !== $after) {
                $item->rotated_at = now();
            }

            $item->version++;
            $item->updated_by = $user->id;
            $item->save();

            $this->versions->snapshot($item, 'restored', $user, $version->version);
            $this->audit->record(VaultAuditAction::Restored, $user, $item, null, ['version' => $item->version, 'restored_from' => $version->version]);

            activity('vault')->performedOn($item)->causedBy($user)
                ->withProperties(['vault_id' => $item->vault_id, 'name' => $item->name, 'restored_from' => $version->version])
                ->log('Vault item restored');

            return $item;
        });
    }
}
