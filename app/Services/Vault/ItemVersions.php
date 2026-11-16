<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\User;
use App\Models\VaultItem;
use App\Models\VaultItemVersion;

/**
 * Version history (spec §14.4): after every save the whole item — metadata and every
 * secret — is sealed as one JSON snapshot (AAD "vault_version:{item}:{version}").
 * Restoring applies a snapshot as a new version, so history only grows.
 */
final readonly class ItemVersions
{
    /** Metadata a snapshot carries (and a restore puts back). */
    public const array ATTRIBUTES = ['name', 'vault_id', 'type', 'provider', 'subtitle', 'username', 'url', 'environment', 'fields', 'custom_fields', 'tags', 'expires_at', 'strength', 'has_totp', 'restricted'];

    public function __construct(private VaultCrypto $crypto, private ItemSecrets $secrets) {}

    public function snapshot(VaultItem $item, string $reason, ?User $by, ?int $restoredFrom = null): VaultItemVersion
    {
        $attributes = [];

        foreach (self::ATTRIBUTES as $key) {
            $attributes[$key] = $item->getAttributes()[$key] ?? null;
        }

        $payload = json_encode(['attributes' => $attributes, 'secrets' => $this->secrets->all($item)], JSON_THROW_ON_ERROR);
        $sealed = $this->crypto->encrypt($item->company_id, $payload, VaultItemVersion::aad($item->id, $item->version));

        return VaultItemVersion::query()->create([
            'company_id' => $item->company_id,
            'vault_item_id' => $item->id,
            'version' => $item->version,
            'reason' => $reason,
            'restored_from' => $restoredFrom,
            'snapshot' => $sealed->ciphertext,
            'key_version' => $sealed->keyVersion,
            'created_by' => $by?->id,
            'created_at' => now(),
        ]);
    }

    /** @return array{attributes: array<string, mixed>, secrets: array<string, string>} */
    public function open(VaultItemVersion $version): array
    {
        $json = $this->crypto->decrypt($version->company_id, $version->snapshot, $version->key_version, VaultItemVersion::aad($version->vault_item_id, $version->version), 'version');
        /** @var array{attributes: array<string, mixed>, secrets: array<string, string>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function reseal(VaultItemVersion $version): void
    {
        $json = $this->crypto->decrypt($version->company_id, $version->snapshot, $version->key_version, VaultItemVersion::aad($version->vault_item_id, $version->version), 'version');
        $sealed = $this->crypto->encrypt($version->company_id, $json, VaultItemVersion::aad($version->vault_item_id, $version->version));
        $version->forceFill(['snapshot' => $sealed->ciphertext, 'key_version' => $sealed->keyVersion])->save();
    }
}
