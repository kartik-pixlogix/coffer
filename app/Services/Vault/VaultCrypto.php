<?php

declare(strict_types=1);

namespace App\Services\Vault;

use SensitiveParameter;

/**
 * Envelope encryption for the vault (spec §14.3, D66). Secrets are sealed with the
 * company's active data key (AES-256-GCM, random nonce, AAD naming the row and field)
 * and stored with the key version; fingerprints are HMAC-SHA256 under a separate key.
 * Parameters holding plaintext are #[SensitiveParameter], so stack traces redact them.
 */
final readonly class VaultCrypto
{
    public function __construct(private VaultKeys $keys) {}

    public function encrypt(int $companyId, #[SensitiveParameter] string $plaintext, string $aad): Sealed
    {
        $version = $this->keys->activeVersion($companyId);

        return new Sealed(Aead::seal($this->keys->dataKey($companyId, $version), $plaintext, $aad), $version);
    }

    public function decrypt(int $companyId, string $ciphertext, int $keyVersion, string $aad, string $what = 'secret'): string
    {
        return Aead::open($this->keys->dataKey($companyId, $keyVersion), $ciphertext, $aad, $what);
    }

    /** True when the ciphertext is under an older data key (a rotation is in progress). */
    public function isStale(int $companyId, int $keyVersion): bool
    {
        return $keyVersion < $this->keys->activeVersion($companyId);
    }

    /**
     * HMAC-SHA256 fingerprint for reuse detection without decrypting (spec §14.3). Equal
     * values in one company give equal fingerprints; nothing is learnt across companies.
     */
    public function fingerprint(int $companyId, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, $this->keys->fingerprintKey($companyId));
    }
}
