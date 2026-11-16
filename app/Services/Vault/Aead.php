<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Exceptions\Vault\VaultDecryptionFailed;
use RuntimeException;
use SensitiveParameter;

/**
 * AES-256-GCM with a random 96-bit nonce and a 128-bit tag (openssl; sodium's AES-GCM
 * needs CPU support). Output is base64(nonce ‖ tag ‖ ciphertext). The AAD binds the
 * ciphertext to where it is stored, so it can't be moved to another row or field.
 */
final class Aead
{
    private const string CIPHER = 'aes-256-gcm';

    private const int NONCE = 12;

    private const int TAG = 16;

    public static function seal(#[SensitiveParameter] string $key, #[SensitiveParameter] string $plaintext, string $aad): string
    {
        $nonce = random_bytes(self::NONCE);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG);

        if ($ciphertext === false) {
            throw new RuntimeException('AES-256-GCM encryption failed.');
        }

        return base64_encode($nonce.$tag.$ciphertext);
    }

    public static function open(#[SensitiveParameter] string $key, string $sealed, string $aad, string $what = 'secret'): string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) < self::NONCE + self::TAG) {
            throw VaultDecryptionFailed::for($what);
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::NONCE + self::TAG),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE),
            substr($raw, self::NONCE, self::TAG),
            $aad,
        );

        if ($plaintext === false) {
            throw VaultDecryptionFailed::for($what);
        }

        return $plaintext;
    }
}
