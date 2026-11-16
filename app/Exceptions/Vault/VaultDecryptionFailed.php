<?php

declare(strict_types=1);

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * AES-GCM refused to open a ciphertext: the wrong key, a changed byte, or a ciphertext
 * moved to another row (the AAD names the row and field). Never carries secret data.
 */
final class VaultDecryptionFailed extends RuntimeException
{
    public static function for(string $what): self
    {
        return new self("A vault {$what} couldn’t be decrypted: the key is wrong or the stored data was changed.");
    }
}
