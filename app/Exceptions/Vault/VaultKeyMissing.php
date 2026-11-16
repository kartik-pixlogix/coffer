<?php

declare(strict_types=1);

namespace App\Exceptions\Vault;

use RuntimeException;

/** A vault key isn't configured, or is malformed. The message says how to fix it. */
final class VaultKeyMissing extends RuntimeException
{
    public static function master(): self
    {
        return new self('VAULT_MASTER_KEY is not set. Run `php artisan coffer:vault-key`, copy the keys it prints into .env, then try again.');
    }

    public static function fingerprint(): self
    {
        return new self('VAULT_FINGERPRINT_KEY is not set. Run `php artisan coffer:vault-key`, copy the keys it prints into .env, then try again.');
    }

    public static function malformed(string $name): self
    {
        return new self("{$name} must be 32 random bytes, base64-encoded with a `base64:` prefix. Run `php artisan coffer:vault-key` for a fresh one.");
    }

    public static function unknownMaster(int $companyId, int $version): self
    {
        return new self("The data key v{$version} of company {$companyId} was wrapped with a master key that isn't configured. Put the old key in VAULT_PREVIOUS_MASTER_KEYS and run `php artisan coffer:vault-rotate --master`.");
    }

    public static function dataKey(int $companyId, int $version): self
    {
        return new self("Company {$companyId} has no vault data key v{$version}.");
    }
}
