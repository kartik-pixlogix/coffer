<?php

declare(strict_types=1);

use App\Exceptions\Vault\VaultDecryptionFailed;
use App\Exceptions\Vault\VaultKeyMissing;
use App\Jobs\ReencryptVaultData;
use App\Models\Company;
use App\Models\VaultItemVersion;
use App\Models\VaultKey;
use App\Models\VaultSecret;
use App\Services\Vault\Aead;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\VaultCrypto;
use App\Services\Vault\VaultKeys;

function freshKeys(): void
{
    app(VaultKeys::class)->flush();
}

function rawKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

test('round-trip: a secret seals under the company key and opens again', function () {
    $company = Company::factory()->create();
    $crypto = app(VaultCrypto::class);

    $sealed = $crypto->encrypt($company->id, 'Vx9#kq!4LmP2@zr8', 'vault_secret:1:password');

    expect($sealed->keyVersion)->toBe(1)
        ->and($sealed->ciphertext)->not->toContain('Vx9#kq')
        ->and($crypto->decrypt($company->id, $sealed->ciphertext, $sealed->keyVersion, 'vault_secret:1:password'))->toBe('Vx9#kq!4LmP2@zr8');

    // A random nonce: the same value never seals the same way twice.
    expect($crypto->encrypt($company->id, 'Vx9#kq!4LmP2@zr8', 'vault_secret:1:password')->ciphertext)->not->toBe($sealed->ciphertext);

    // The data key is stored wrapped, never raw.
    $row = VaultKey::query()->where('company_id', $company->id)->sole();
    expect(strlen((string) base64_decode($row->wrapped_key, true)))->toBe(12 + 16 + 32)
        ->and($row->master_key_id)->toHaveLength(16);
});

test('tampering is detected: a flipped bit or another row’s AAD fails', function () {
    $company = Company::factory()->create();
    $crypto = app(VaultCrypto::class);
    $sealed = $crypto->encrypt($company->id, 'hunter2-but-longer', 'vault_secret:7:password');

    $raw = (string) base64_decode($sealed->ciphertext, true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
    $flipped = base64_encode($raw);

    expect(fn () => $crypto->decrypt($company->id, $flipped, 1, 'vault_secret:7:password'))->toThrow(VaultDecryptionFailed::class)
        ->and(fn () => $crypto->decrypt($company->id, $sealed->ciphertext, 1, 'vault_secret:8:password'))->toThrow(VaultDecryptionFailed::class)
        ->and(fn () => $crypto->decrypt($company->id, $sealed->ciphertext, 1, 'vault_secret:7:totp'))->toThrow(VaultDecryptionFailed::class)
        ->and(fn () => $crypto->decrypt($company->id, 'not base64!', 1, 'vault_secret:7:password'))->toThrow(VaultDecryptionFailed::class);
});

test('one company’s ciphertext does not open under another company’s key', function () {
    [$a, $b] = Company::factory()->count(2)->create();
    $crypto = app(VaultCrypto::class);
    $sealed = $crypto->encrypt($a->id, 'shared-nothing', 'vault_secret:1:password');
    $crypto->encrypt($b->id, 'b has a key too', 'aad');

    expect(fn () => $crypto->decrypt($b->id, $sealed->ciphertext, 1, 'vault_secret:1:password'))->toThrow(VaultDecryptionFailed::class);
});

test('a wrong master key cannot unwrap the data key', function () {
    $company = Company::factory()->create();
    $sealed = app(VaultCrypto::class)->encrypt($company->id, 'p@ss', 'aad');

    config(['vault.master_key' => rawKey()]);
    freshKeys();

    expect(fn () => app(VaultCrypto::class)->decrypt($company->id, $sealed->ciphertext, 1, 'aad'))
        ->toThrow(VaultKeyMissing::class, 'VAULT_PREVIOUS_MASTER_KEYS');
});

test('a missing or malformed key gives a clear error', function () {
    $company = Company::factory()->create();

    config(['vault.master_key' => null]);
    freshKeys();
    expect(fn () => app(VaultCrypto::class)->encrypt($company->id, 'x', 'aad'))->toThrow(VaultKeyMissing::class, 'VAULT_MASTER_KEY is not set');

    config(['vault.master_key' => 'base64:c2hvcnQ=']);
    expect(fn () => app(VaultCrypto::class)->encrypt($company->id, 'x', 'aad'))->toThrow(VaultKeyMissing::class, 'must be 32 random bytes');

    config(['vault.master_key' => rawKey(), 'vault.fingerprint_key' => '']);
    expect(fn () => app(VaultCrypto::class)->fingerprint($company->id, 'x'))->toThrow(VaultKeyMissing::class, 'VAULT_FINGERPRINT_KEY is not set');
});

test('fingerprints match equal values within a company only and differ from a plain hash', function () {
    [$a, $b] = Company::factory()->count(2)->create();
    $crypto = app(VaultCrypto::class);

    expect($crypto->fingerprint($a->id, 'Kiran@2025'))->toBe($crypto->fingerprint($a->id, 'Kiran@2025'))
        ->and($crypto->fingerprint($a->id, 'Kiran@2025'))->not->toBe($crypto->fingerprint($a->id, 'Kiran@2026'))
        ->and($crypto->fingerprint($a->id, 'Kiran@2025'))->not->toBe($crypto->fingerprint($b->id, 'Kiran@2025'))
        ->and($crypto->fingerprint($a->id, 'Kiran@2025'))->not->toBe(hash('sha256', 'Kiran@2025'));
});

test('master-key rotation re-wraps data keys and old ciphertext still opens', function () {
    $company = Company::factory()->create();
    $sealed = app(VaultCrypto::class)->encrypt($company->id, 'before-rotation', 'aad');
    $old = config('vault.master_key');

    config(['vault.master_key' => rawKey(), 'vault.previous_master_keys' => $old]);
    freshKeys();

    $this->artisan('coffer:vault-rotate --master')->expectsOutputToContain('Re-wrapped 1 data key')->assertSuccessful();

    // The old master key is no longer needed.
    config(['vault.previous_master_keys' => '']);
    freshKeys();

    expect(app(VaultCrypto::class)->decrypt($company->id, $sealed->ciphertext, 1, 'aad'))->toBe('before-rotation')
        ->and(VaultKey::query()->sole()->master_key_id)->toBe(VaultKeys::idOf((string) base64_decode(substr((string) config('vault.master_key'), 7), true)));

    $this->artisan('coffer:vault-rotate --master')->expectsOutputToContain('already wrapped')->assertSuccessful();
});

test('data-key rotation re-encrypts every row in chunks and retires the old key', function () {
    config(['vault.rotation_chunk' => 2]);
    $company = Company::factory()->create();
    $item = vaultItemFor($company, ['password' => 'one-two-three-four', 'totp' => 'JBSWY3DPEHPK3PXP', 'notes' => 'Break-glass only.']);
    $before = app(ItemSecrets::class)->all($item);

    $this->artisan('coffer:vault-rotate', ['--company' => [$company->id], '--sync' => true])->assertSuccessful();
    freshKeys();

    expect(VaultSecret::query()->withoutGlobalScopes()->pluck('key_version')->unique()->values()->all())->toBe([2])
        ->and(VaultItemVersion::query()->withoutGlobalScopes()->pluck('key_version')->unique()->values()->all())->toBe([2])
        ->and(app(ItemSecrets::class)->all($item))->toBe($before)
        ->and(VaultKey::query()->where('version', 1)->sole()->retired_at)->not->toBeNull()
        ->and(ReencryptVaultData::remaining($company->id, 2))->toBe(0);

    // New data seals with the new key.
    expect(app(VaultCrypto::class)->encrypt($company->id, 'x', 'aad')->keyVersion)->toBe(2);
});

test('a rotation that stopped half-way resumes instead of starting over', function () {
    config(['vault.rotation_chunk' => 1]);
    $company = Company::factory()->create();
    vaultItemFor($company, ['password' => 'one-two-three-four', 'notes' => 'n']);

    $version = app(VaultKeys::class)->addVersion($company->id);
    app()->call([new ReencryptVaultData($company->id, $version, chain: false), 'handle']);
    expect(ReencryptVaultData::remaining($company->id, $version))->toBeGreaterThan(0);

    $this->artisan('coffer:vault-rotate', ['--company' => [$company->id], '--sync' => true])
        ->expectsOutputToContain('resuming rotation to key v2')->assertSuccessful();

    expect(ReencryptVaultData::remaining($company->id, 2))->toBe(0)
        ->and(VaultKey::query()->where('company_id', $company->id)->max('version'))->toBe(2);
});

test('Aead output is nonce, tag and ciphertext', function () {
    $key = random_bytes(32);
    $sealed = Aead::seal($key, 'abc', 'x');

    expect(strlen((string) base64_decode($sealed, true)))->toBe(12 + 16 + 3)
        ->and(Aead::open($key, $sealed, 'x'))->toBe('abc');
});

test('the key command prints keys and writes nothing', function () {
    $env = base_path('.env');
    $before = is_file($env) ? (string) file_get_contents($env) : null;

    $this->artisan('coffer:vault-key')->expectsOutputToContain('VAULT_MASTER_KEY=base64:')->assertSuccessful();

    expect(is_file($env) ? (string) file_get_contents($env) : null)->toBe($before);
});
