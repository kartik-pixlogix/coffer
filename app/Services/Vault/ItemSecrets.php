<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\VaultItem;
use App\Models\VaultSecret;
use SensitiveParameter;

/**
 * Reads and writes an item's encrypted fields (one VaultSecret row each, AAD
 * "vault_secret:{item}:{field}"). Plaintext exists only in the return values here.
 */
final readonly class ItemSecrets
{
    public function __construct(private VaultCrypto $crypto) {}

    public function get(VaultItem $item, string $field): ?string
    {
        $row = VaultSecret::query()->where('vault_item_id', $item->id)->where('field', $field)->first();

        return $row === null ? null : $this->open($row);
    }

    /** @return array<string, string> field → plaintext */
    public function all(VaultItem $item): array
    {
        $out = [];

        foreach (VaultSecret::query()->where('vault_item_id', $item->id)->orderBy('field')->get() as $row) {
            $out[$row->field] = $this->open($row);
        }

        return $out;
    }

    /** @return list<string> the fields that hold a value (no decryption) */
    public function keys(VaultItem $item): array
    {
        return array_values(VaultSecret::query()->where('vault_item_id', $item->id)->orderBy('field')->get(['field'])->map(fn (VaultSecret $s): string => $s->field)->all());
    }

    /** Store a value, or remove the field when it's empty. Returns true when the value changed. */
    public function put(VaultItem $item, string $field, #[SensitiveParameter] ?string $value, bool $fingerprint = false): bool
    {
        $row = VaultSecret::query()->where('vault_item_id', $item->id)->where('field', $field)->first();

        if ($value === null || $value === '') {
            $row?->delete();

            return $row !== null;
        }

        if ($row !== null && hash_equals($this->open($row), $value)) {
            return false;
        }

        $sealed = $this->crypto->encrypt($item->company_id, $value, VaultSecret::aad($item->id, $field));
        $attributes = [
            'ciphertext' => $sealed->ciphertext,
            'key_version' => $sealed->keyVersion,
            'fingerprint' => $fingerprint ? $this->crypto->fingerprint($item->company_id, $value) : null,
        ];

        if ($row === null) {
            VaultSecret::query()->create(['company_id' => $item->company_id, 'vault_item_id' => $item->id, 'field' => $field, ...$attributes]);
        } else {
            $row->forceFill($attributes)->save();
        }

        return true;
    }

    /**
     * Replace every secret with `$values` (version restore).
     *
     * @param  array<string, string>  $values
     */
    public function replaceAll(VaultItem $item, #[SensitiveParameter] array $values, callable $fingerprinted): void
    {
        foreach (array_diff($this->keys($item), array_keys($values)) as $gone) {
            $this->put($item, $gone, null);
        }

        foreach ($values as $field => $value) {
            $this->put($item, (string) $field, $value, (bool) $fingerprinted((string) $field));
        }
    }

    public function open(VaultSecret $row): string
    {
        return $this->crypto->decrypt($row->company_id, $row->ciphertext, $row->key_version, VaultSecret::aad($row->vault_item_id, $row->field));
    }

    /** Re-seal under the company's active key (data-key rotation). */
    public function reseal(VaultSecret $row): void
    {
        $plain = $this->open($row);
        $sealed = $this->crypto->encrypt($row->company_id, $plain, VaultSecret::aad($row->vault_item_id, $row->field));
        $row->forceFill(['ciphertext' => $sealed->ciphertext, 'key_version' => $sealed->keyVersion])->save();
    }
}
