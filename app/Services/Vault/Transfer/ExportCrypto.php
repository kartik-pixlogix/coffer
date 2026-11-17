<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use JsonException;
use RuntimeException;
use SensitiveParameter;

/**
 * The encrypted vault export file (spec §14.4 "an encrypted, password-protected JSON
 * file", D77). A documented JSON envelope:
 *
 *     {
 *       "format": "coffer-vault-export", "version": 1,
 *       "created_at": "2026-11-17T10:00:00+05:30", "company": "Saarthi Portal JV", "items": 13,
 *       "kdf": {"name": "argon2id", "salt": "<b64 16 bytes>", "opslimit": 3, "memlimit": 67108864}
 *           or {"name": "pbkdf2-sha256", "salt": "<b64 16 bytes>", "iterations": 600000},
 *       "cipher": {"name": "aes-256-gcm", "nonce": "<b64 12 bytes>", "tag": "<b64 16 bytes>"},
 *       "ciphertext": "<b64>"
 *     }
 *
 * The 256-bit key comes from the passphrase by Argon2id (libsodium's crypto_pwhash) when
 * the server has it, else PBKDF2-HMAC-SHA256 at 600,000 iterations. Every header field
 * except the ciphertext and tag is bound as AES-GCM additional data, so none of it can be
 * changed without the file failing to open. The plaintext is JSON: {"items": [...]}.
 */
final class ExportCrypto
{
    public const string FORMAT = 'coffer-vault-export';

    public const int VERSION = 1;

    public const int PBKDF2_ITERATIONS = 600_000;

    public const int MIN_PASSPHRASE = 12;

    public static function argonAvailable(): bool
    {
        return function_exists('sodium_crypto_pwhash') && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
    }

    /**
     * @param  array{company: string, items: int}  $about
     * @return string the envelope as pretty JSON
     */
    public function seal(#[SensitiveParameter] string $plaintext, #[SensitiveParameter] string $passphrase, array $about, ?bool $argon = null): string
    {
        $argon ??= self::argonAvailable();
        $salt = random_bytes(16);
        $kdf = $argon
            ? ['name' => 'argon2id', 'salt' => base64_encode($salt), 'opslimit' => 3, 'memlimit' => 64 * 1024 * 1024]
            : ['name' => 'pbkdf2-sha256', 'salt' => base64_encode($salt), 'iterations' => self::PBKDF2_ITERATIONS];
        $nonce = random_bytes(12);

        $header = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'company' => $about['company'],
            'items' => $about['items'],
            'kdf' => $kdf,
            'cipher' => ['name' => 'aes-256-gcm', 'nonce' => base64_encode($nonce)],
        ];

        $key = $this->key($passphrase, $kdf);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($header), 16);

        if ($ciphertext === false) {
            throw new RuntimeException('The export couldn’t be encrypted.');
        }

        $header['cipher']['tag'] = base64_encode($tag);
        $header['ciphertext'] = base64_encode($ciphertext);

        return json_encode($header, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Is this file a Coffer export (without opening it)? */
    public static function looksLikeExport(string $bytes): bool
    {
        $head = ltrim(substr($bytes, 0, 200));

        return str_starts_with($head, '{') && str_contains($head, '"'.self::FORMAT.'"');
    }

    /**
     * @throws RuntimeException with a message for the person (wrong passphrase, damaged file)
     */
    public function open(string $envelope, #[SensitiveParameter] string $passphrase): string
    {
        try {
            /** @var mixed $data */
            $data = json_decode($envelope, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('This isn’t a Coffer export file.');
        }

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION
            || ! is_array($data['kdf'] ?? null) || ! is_array($data['cipher'] ?? null) || ! is_string($data['ciphertext'] ?? null)) {
            throw new RuntimeException('This isn’t a Coffer export file, or it’s from a newer version.');
        }

        $cipher = $data['cipher'];
        $nonce = base64_decode((string) ($cipher['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($cipher['tag'] ?? ''), true);
        $ciphertext = base64_decode($data['ciphertext'], true);

        if (($cipher['name'] ?? null) !== 'aes-256-gcm' || $nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new RuntimeException('This export file is damaged.');
        }

        $header = $data;
        unset($header['ciphertext'], $header['cipher']['tag']);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key($passphrase, $data['kdf']), OPENSSL_RAW_DATA, $nonce, $tag, self::aad($header));

        if ($plaintext === false) {
            throw new RuntimeException('That passphrase doesn’t open this file (or the file was changed).');
        }

        return $plaintext;
    }

    /** @param array<string, mixed> $kdf */
    private function key(#[SensitiveParameter] string $passphrase, array $kdf): string
    {
        $salt = base64_decode((string) ($kdf['salt'] ?? ''), true);

        if ($salt === false || strlen($salt) !== 16) {
            throw new RuntimeException('This export file is damaged.');
        }

        return match ($kdf['name'] ?? null) {
            'argon2id' => self::argonAvailable()
                ? sodium_crypto_pwhash(32, $passphrase, $salt, self::bounded($kdf['opslimit'] ?? 0, 1, 10), self::bounded($kdf['memlimit'] ?? 0, 8 * 1024 * 1024, 1024 * 1024 * 1024), SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13)
                : throw new RuntimeException('This server can’t open Argon2id files (libsodium is missing).'),
            'pbkdf2-sha256' => hash_pbkdf2('sha256', $passphrase, $salt, self::bounded($kdf['iterations'] ?? 0, 100_000, 10_000_000), 32, true),
            default => throw new RuntimeException('This export file uses an unknown key derivation.'),
        };
    }

    /**
     * @param  int<1, max>  $min
     * @return int<1, max>
     */
    private static function bounded(mixed $value, int $min, int $max): int
    {
        $n = is_int($value) ? $value : 0;

        if ($n < $min || $n > $max) {
            throw new RuntimeException('This export file is damaged.');
        }

        return $n;
    }

    /** @param array<string, mixed> $header */
    private static function aad(array $header): string
    {
        return json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
