<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Exceptions\Vault\VaultKeyMissing;
use App\Models\VaultKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use SensitiveParameter;

/**
 * The key hierarchy (spec §14.3, D66): VAULT_MASTER_KEY (env) wraps one random 256-bit
 * data key per company and version; VAULT_FINGERPRINT_KEY keys the reuse fingerprints.
 * Unwrapped keys live only in this object's memory for the request or job.
 */
final class VaultKeys
{
    /** @var array<string, string> "company:version" → raw data key */
    private array $dataKeys = [];

    /** @var array<int, int> company → active version */
    private array $active = [];

    /** The raw current master key. */
    public function master(): string
    {
        return self::decode(config('vault.master_key'), 'VAULT_MASTER_KEY') ?? throw VaultKeyMissing::master();
    }

    /** "a1b2…": names a master key without revealing it (first 16 hex of its SHA-256). */
    public static function idOf(#[SensitiveParameter] string $rawKey): string
    {
        return substr(hash('sha256', $rawKey), 0, 16);
    }

    /** @return array<string, string> id → raw key, current first, then VAULT_PREVIOUS_MASTER_KEYS */
    public function masters(): array
    {
        $current = $this->master();
        $keys = [self::idOf($current) => $current];

        foreach (array_filter(array_map('trim', explode(',', (string) config('vault.previous_master_keys')))) as $value) {
            $raw = self::decode($value, 'VAULT_PREVIOUS_MASTER_KEYS');

            if ($raw !== null) {
                $keys[self::idOf($raw)] ??= $raw;
            }
        }

        return $keys;
    }

    /** Raw per-company key for HMAC fingerprints, derived from VAULT_FINGERPRINT_KEY. */
    public function fingerprintKey(int $companyId): string
    {
        $root = self::decode(config('vault.fingerprint_key'), 'VAULT_FINGERPRINT_KEY') ?? throw VaultKeyMissing::fingerprint();

        return hash_hkdf('sha256', $root, 32, "coffer-vault-fingerprint:{$companyId}");
    }

    /** The version new data is sealed with; creates the company's first data key on first use. */
    public function activeVersion(int $companyId): int
    {
        if (isset($this->active[$companyId])) {
            return $this->active[$companyId];
        }

        $version = $this->query($companyId)->whereNull('retired_at')->max('version');

        return $this->active[$companyId] = is_numeric($version) ? (int) $version : $this->create($companyId, 1);
    }

    /** The raw data key for a version. */
    public function dataKey(int $companyId, int $version): string
    {
        $cacheKey = "{$companyId}:{$version}";

        if (isset($this->dataKeys[$cacheKey])) {
            return $this->dataKeys[$cacheKey];
        }

        $row = $this->query($companyId)->where('version', $version)->first() ?? throw VaultKeyMissing::dataKey($companyId, $version);
        $master = $this->masters()[$row->master_key_id] ?? throw VaultKeyMissing::unknownMaster($companyId, $version);

        return $this->dataKeys[$cacheKey] = Aead::open($master, $row->wrapped_key, self::wrapAad($companyId, $version), 'data key');
    }

    /** Start a data-key rotation: a new version becomes active for new data. */
    public function addVersion(int $companyId): int
    {
        $this->activeVersion($companyId);
        $next = (int) $this->query($companyId)->max('version') + 1;

        return $this->active[$companyId] = $this->create($companyId, $next);
    }

    /** Older versions nothing references any more stop being usable. */
    public function retireBelow(int $companyId, int $version): void
    {
        $this->query($companyId)->where('version', '<', $version)->whereNull('retired_at')->update(['retired_at' => now()]);
    }

    /**
     * Master-key rotation: re-wrap every data key that isn't wrapped by the current master.
     * Resumable: already re-wrapped rows are skipped.
     */
    public function rewrapAll(?callable $progress = null): int
    {
        $current = $this->master();
        $currentId = self::idOf($current);
        $count = 0;

        VaultKey::query()->where('master_key_id', '!=', $currentId)->orderBy('id')
            ->chunkById(100, function ($rows) use ($current, $currentId, &$count, $progress): void {
                foreach ($rows as $row) {
                    /** @var VaultKey $row */
                    $raw = $this->dataKey($row->company_id, $row->version);
                    $row->forceFill([
                        'wrapped_key' => Aead::seal($current, $raw, self::wrapAad($row->company_id, $row->version)),
                        'master_key_id' => $currentId,
                    ])->save();
                    $count++;

                    if ($progress !== null) {
                        $progress($row);
                    }
                }
            });

        return $count;
    }

    /** Forget cached keys (between tests, after rotation). */
    public function flush(): void
    {
        $this->dataKeys = [];
        $this->active = [];
    }

    private function create(int $companyId, int $version): int
    {
        $master = $this->master();
        $raw = random_bytes(32);

        try {
            VaultKey::query()->create([
                'company_id' => $companyId,
                'version' => $version,
                'wrapped_key' => Aead::seal($master, $raw, self::wrapAad($companyId, $version)),
                'master_key_id' => self::idOf($master),
            ]);
            $this->dataKeys["{$companyId}:{$version}"] = $raw;
        } catch (UniqueConstraintViolationException) {
            // Another request created it first; use theirs.
        }

        return $version;
    }

    /** @return Builder<VaultKey> */
    private function query(int $companyId): Builder
    {
        return VaultKey::query()->where('company_id', $companyId);
    }

    private static function wrapAad(int $companyId, int $version): string
    {
        return "vault_key:{$companyId}:{$version}";
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
