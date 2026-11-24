<?php

declare(strict_types=1);

namespace App\Services\Vault\MasterKeys;

use SensitiveParameter;

/**
 * Where the master key lives (Phase 10, D152). It only ever wraps and unwraps company data
 * keys (D66); it never sees a secret or a business field. `env` keeps the key in
 * VAULT_MASTER_KEY; a KMS driver keeps it in the KMS, where it never leaves, and
 * wrap/unwrap become KMS calls. VaultKeys caches unwrapped data keys for the request or job
 * only, so a KMS is called once per company and key version, not per value.
 */
interface MasterKey
{
    /** Names the master key that wraps new data keys (stored as `vault_keys.master_key_id`). */
    public function currentId(): string;

    /** @return list<string> every master key id this driver can unwrap with, current first */
    public function knownIds(): array;

    public function wrap(#[SensitiveParameter] string $rawDataKey, string $aad): string;

    public function unwrap(string $masterKeyId, string $wrapped, string $aad): string;
}
