<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Vault\Aead;
use App\Services\Vault\VaultKeys;
use SensitiveParameter;

/**
 * Field-level encryption for sensitive business data (Phase 10, D149): GSTIN, address, bank
 * labels, partner and member emails, and free-text notes. Same envelope scheme as the vault
 * (spec §14.3, D66): AES-256-GCM under the company's data key, which the master key wraps,
 * with AAD naming the company and the field, so a value can't be moved to another company or
 * column. Stored as `cf1:{key version}:{base64}`; anything without the prefix is legacy
 * plaintext and is returned as it is (until the backfill migration has sealed it).
 *
 * Fields that must be found or deduplicated without decrypting get a blind index: an HMAC of
 * the normalised value under a per-company, per-field key (`blindIndex`).
 */
final readonly class FieldCrypto
{
    public const string PREFIX = 'cf1:';

    public function __construct(private VaultKeys $keys) {}

    public static function isSealed(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** "table.column", the field part of the AAD. */
    public function seal(int $companyId, #[SensitiveParameter] string $plaintext, string $field): string
    {
        $version = $this->keys->activeVersion($companyId);

        return self::PREFIX.$version.':'.Aead::seal($this->keys->dataKey($companyId, $version), $plaintext, self::aad($companyId, $field));
    }

    public function open(int $companyId, string $stored, string $field): string
    {
        if (! self::isSealed($stored)) {
            return $stored;
        }

        [$version, $ciphertext] = explode(':', substr($stored, strlen(self::PREFIX)), 2) + [1 => ''];

        return Aead::open($this->keys->dataKey($companyId, (int) $version), $ciphertext, self::aad($companyId, $field), $field);
    }

    /** Re-seals under the active data key (rotation); null when it already is. */
    public function reseal(int $companyId, string $stored, string $field): ?string
    {
        if (self::isSealed($stored) && (int) explode(':', substr($stored, strlen(self::PREFIX)), 2)[0] === $this->keys->activeVersion($companyId)) {
            return null;
        }

        return $this->seal($companyId, $this->open($companyId, $stored, $field), $field);
    }

    /** HMAC-SHA256 of the trimmed, lower-cased value: equal values in one company and field match. */
    public function blindIndex(int $companyId, #[SensitiveParameter] string $value, string $field): string
    {
        $key = hash_hkdf('sha256', $this->keys->fingerprintKey($companyId), 32, "coffer-field-index:{$field}");

        return hash_hmac('sha256', mb_strtolower(trim($value)), $key);
    }

    private static function aad(int $companyId, string $field): string
    {
        return "field:{$companyId}:{$field}";
    }
}
