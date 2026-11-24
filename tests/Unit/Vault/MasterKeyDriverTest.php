<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\VaultKey;
use App\Services\Vault\Aead;
use App\Services\Vault\MasterKeys\EnvMasterKey;
use App\Services\Vault\MasterKeys\MasterKey;
use App\Services\Vault\VaultCrypto;
use App\Services\Vault\VaultKeys;

/* The master-key driver seam (D152): VaultKeys only wraps and unwraps through MasterKey. */

test('the env driver is the default and names keys by their hash', function () {
    expect(app(MasterKey::class))->toBeInstanceOf(EnvMasterKey::class)
        ->and(app(MasterKey::class)->currentId())->toBe(EnvMasterKey::idOf((string) base64_decode(substr((string) config('vault.master_key'), 7), true)));
});

test('an unknown driver is refused with a clear message', function () {
    config(['vault.master_key_driver' => 'aws-kms']);

    expect(fn () => app(MasterKey::class))->toThrow(RuntimeException::class, 'not available');
});

test('any driver can stand in: data keys are wrapped and unwrapped only through it', function () {
    // A stand-in "KMS" that keeps its own key and counts calls.
    $kms = new class implements MasterKey
    {
        public int $unwraps = 0;

        private string $key;

        public function __construct()
        {
            $this->key = random_bytes(32);
        }

        public function currentId(): string
        {
            return 'kms:test-key';
        }

        public function knownIds(): array
        {
            return ['kms:test-key'];
        }

        public function wrap(string $rawDataKey, string $aad): string
        {
            return Aead::seal($this->key, $rawDataKey, $aad);
        }

        public function unwrap(string $masterKeyId, string $wrapped, string $aad): string
        {
            $this->unwraps++;

            return Aead::open($this->key, $wrapped, $aad);
        }
    };

    app()->instance(MasterKey::class, $kms);
    app()->forgetScopedInstances();
    $company = Company::factory()->create();

    $sealed = app(VaultCrypto::class)->encrypt($company->id, 'secret', 'aad');
    expect(VaultKey::query()->where('company_id', $company->id)->sole()->master_key_id)->toBe('kms:test-key');

    // A fresh request: the data key is unwrapped once, then cached for the request.
    app()->forgetScopedInstances();
    $crypto = app(VaultCrypto::class);
    expect($crypto->decrypt($company->id, $sealed->ciphertext, $sealed->keyVersion, 'aad'))->toBe('secret')
        ->and($crypto->decrypt($company->id, $sealed->ciphertext, $sealed->keyVersion, 'aad'))->toBe('secret')
        ->and($kms->unwraps)->toBe(1)
        ->and(app(VaultKeys::class)->masterIds())->toBe(['kms:test-key']);
});
