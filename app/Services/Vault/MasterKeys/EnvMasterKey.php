<?php

declare(strict_types=1);

namespace App\Services\Vault\MasterKeys;

use App\Exceptions\Vault\VaultKeyMissing;
use App\Services\Vault\Aead;
use SensitiveParameter;

/**
 * The `env` master-key driver (D66, D152): VAULT_MASTER_KEY wraps data keys with AES-256-GCM;
 * VAULT_PREVIOUS_MASTER_KEYS still unwrap during a master-key rotation. A key's id is the first
 * 16 hex of its SHA-256, so the database names it without revealing it.
 */
final class EnvMasterKey implements MasterKey
{
    public static function idOf(#[SensitiveParameter] string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 16);
    }

    public function currentId(): string
    {
        return array_key_first($this->keys()) ?? throw VaultKeyMissing::master();
    }

    public function knownIds(): array
    {
        return array_keys($this->keys());
    }

    public function wrap(#[SensitiveParameter] string $rawDataKey, string $aad): string
    {
        return Aead::seal($this->keys()[$this->currentId()], $rawDataKey, $aad);
    }

    public function unwrap(string $masterKeyId, string $wrapped, string $aad): string
    {
        // VaultKeys checks knownIds() first and names the company and version when it's missing.
        $master = $this->keys()[$masterKeyId] ?? throw VaultKeyMissing::master();

        return Aead::open($master, $wrapped, $aad, 'data key');
    }

    /**
     * Read from config each time (cheap), so a rotation in the same process sees the new key.
     *
     * @return array<string, string> id → raw key, current first
     */
    private function keys(): array
    {
        $current = self::decode(config('vault.master_key'), 'VAULT_MASTER_KEY') ?? throw VaultKeyMissing::master();
        $keys = [self::idOf($current) => $current];

        foreach (array_filter(array_map('trim', explode(',', (string) config('vault.previous_master_keys')))) as $value) {
            $raw = self::decode($value, 'VAULT_PREVIOUS_MASTER_KEYS');

            if ($raw !== null) {
                $keys[self::idOf($raw)] ??= $raw;
            }
        }

        return $keys;
    }

    private static function decode(mixed $value, string $name): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $raw = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;

        if ($raw === false || strlen($raw) !== 32) {
            throw VaultKeyMissing::malformed($name);
        }

        return $raw;
    }
}
