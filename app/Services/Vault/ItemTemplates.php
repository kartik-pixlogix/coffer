<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultItemType;
use App\Models\VaultItem;

/**
 * Each item type's fields (spec §14.1 table; 🔒 = `secret`). Every item also has a name,
 * vault, environment, tags, an expiry date, notes 🔒, custom fields and attachments 🔒.
 * The add/edit form renders from this list, and the server validates and classifies
 * against it, so a field is encrypted exactly when the spec marks it.
 *
 * `primary` is the secret the drawer shows with Reveal / Copy (and whose strength is
 * scored); `fingerprint` fields get an HMAC for reuse detection (passwords only).
 *
 * @phpstan-type Field array{key: string, label: string, kind: string, secret: bool, primary?: bool, fingerprint?: bool, options?: list<string>, placeholder?: string, wide?: bool}
 */
final class ItemTemplates
{
    /** Item-level secret notes (spec: "Every item can have … notes 🔒"). */
    public const string NOTES = 'notes';

    /** Field kinds the form knows. */
    public const array KINDS = ['text', 'url', 'email', 'number', 'select', 'textarea', 'toggle', 'secret', 'secret_text', 'totp'];

    /**
     * @return list<Field>
     */
    public static function fields(VaultItemType $type): array
    {
        $password = fn (string $label = 'Password'): array => ['key' => 'password', 'label' => $label, 'kind' => 'secret', 'secret' => true, 'primary' => true, 'fingerprint' => true];

        return match ($type) {
            VaultItemType::Login => [
                ['key' => 'url', 'label' => 'URL', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
                ['key' => 'username', 'label' => 'Username or email', 'kind' => 'text', 'secret' => false],
                $password(),
                ['key' => 'totp', 'label' => '2FA setup key (TOTP)', 'kind' => 'totp', 'secret' => true, 'wide' => true],
                ['key' => 'recovery_codes', 'label' => 'Recovery codes', 'kind' => 'secret_text', 'secret' => true, 'wide' => true],
            ],
            VaultItemType::ApiKey => [
                ['key' => 'key', 'label' => 'Key or token', 'kind' => 'secret', 'secret' => true, 'primary' => true],
                ['key' => 'secret', 'label' => 'Secret', 'kind' => 'secret', 'secret' => true],
                ['key' => 'scopes', 'label' => 'Scopes', 'kind' => 'text', 'secret' => false, 'placeholder' => 'deploy, read:org'],
                ['key' => 'url', 'label' => 'Where to manage it', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
            ],
            VaultItemType::Server => [
                ['key' => 'host', 'label' => 'Host', 'kind' => 'text', 'secret' => false, 'placeholder' => '203.0.113.24'],
                ['key' => 'port', 'label' => 'Port', 'kind' => 'number', 'secret' => false, 'placeholder' => '22'],
                ['key' => 'user', 'label' => 'User', 'kind' => 'text', 'secret' => false, 'placeholder' => 'root'],
                $password(),
                ['key' => 'private_key', 'label' => 'Private key', 'kind' => 'secret_text', 'secret' => true, 'wide' => true, 'placeholder' => '-----BEGIN OPENSSH PRIVATE KEY-----'],
                ['key' => 'passphrase', 'label' => 'Key passphrase', 'kind' => 'secret', 'secret' => true],
            ],
            VaultItemType::Database => [
                ['key' => 'engine', 'label' => 'Engine', 'kind' => 'select', 'secret' => false, 'options' => ['MySQL', 'MariaDB', 'PostgreSQL', 'SQL Server', 'MongoDB', 'Redis', 'Other']],
                ['key' => 'host', 'label' => 'Host', 'kind' => 'text', 'secret' => false],
                ['key' => 'port', 'label' => 'Port', 'kind' => 'number', 'secret' => false, 'placeholder' => '3306'],
                ['key' => 'database', 'label' => 'Database', 'kind' => 'text', 'secret' => false],
                ['key' => 'user', 'label' => 'User', 'kind' => 'text', 'secret' => false],
                $password(),
                ['key' => 'connection_string', 'label' => 'Connection string', 'kind' => 'secret_text', 'secret' => true, 'wide' => true],
            ],
            VaultItemType::Hosting => [
                ['key' => 'url', 'label' => 'Panel URL', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
                ['key' => 'ftp_host', 'label' => 'FTP host', 'kind' => 'text', 'secret' => false],
                ['key' => 'username', 'label' => 'User', 'kind' => 'text', 'secret' => false],
                $password(),
                ['key' => 'nameservers', 'label' => 'Nameservers', 'kind' => 'textarea', 'secret' => false, 'wide' => true],
            ],
            VaultItemType::Domain => [
                ['key' => 'domain', 'label' => 'Domain', 'kind' => 'text', 'secret' => false, 'placeholder' => 'example.in'],
                ['key' => 'registrar', 'label' => 'Registrar', 'kind' => 'text', 'secret' => false],
                ['key' => 'url', 'label' => 'Registrar login URL', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
                ['key' => 'auto_renew', 'label' => 'Auto-renew', 'kind' => 'toggle', 'secret' => false],
                ['key' => 'nameservers', 'label' => 'Nameservers', 'kind' => 'textarea', 'secret' => false, 'wide' => true],
            ],
            VaultItemType::Email => [
                ['key' => 'username', 'label' => 'Address', 'kind' => 'email', 'secret' => false],
                $password('Password or app password'),
                ['key' => 'url', 'label' => 'Webmail URL', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
                ['key' => 'imap', 'label' => 'IMAP settings', 'kind' => 'text', 'secret' => false, 'placeholder' => 'imap.gmail.com:993 SSL'],
                ['key' => 'smtp', 'label' => 'SMTP settings', 'kind' => 'text', 'secret' => false, 'placeholder' => 'smtp.gmail.com:587 STARTTLS'],
            ],
            VaultItemType::Payment => [
                ['key' => 'mode', 'label' => 'Mode', 'kind' => 'select', 'secret' => false, 'options' => ['Test', 'Live']],
                ['key' => 'publishable_key', 'label' => 'Publishable key / key ID', 'kind' => 'text', 'secret' => false],
                ['key' => 'secret_key', 'label' => 'Secret key', 'kind' => 'secret', 'secret' => true, 'primary' => true],
                ['key' => 'webhook_secret', 'label' => 'Webhook secret', 'kind' => 'secret', 'secret' => true],
                ['key' => 'url', 'label' => 'Dashboard URL', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
            ],
            VaultItemType::Licence => [
                ['key' => 'licence_key', 'label' => 'Licence key', 'kind' => 'secret', 'secret' => true, 'primary' => true],
                ['key' => 'seats', 'label' => 'Seats', 'kind' => 'number', 'secret' => false],
                ['key' => 'username', 'label' => 'Licensed to', 'kind' => 'text', 'secret' => false],
                ['key' => 'url', 'label' => 'Account URL', 'kind' => 'url', 'secret' => false, 'placeholder' => 'https://'],
            ],
            VaultItemType::Note => [
                ['key' => 'note', 'label' => 'Note', 'kind' => 'secret_text', 'secret' => true, 'primary' => true, 'wide' => true],
            ],
        };
    }

    /** What the expiry date is called for the type (spec §14.4 expiry alerts). */
    public static function expiryLabel(VaultItemType $type): string
    {
        return match ($type) {
            VaultItemType::Licence => 'Renewal date',
            VaultItemType::Hosting, VaultItemType::Domain => 'Renews on',
            default => 'Expires on',
        };
    }

    /** @return array<string, Field> */
    public static function byKey(VaultItemType $type): array
    {
        $out = [];

        foreach (self::fields($type) as $field) {
            $out[$field['key']] = $field;
        }

        return $out;
    }

    /** The field the drawer shows with Reveal / Copy, if the type has one. */
    public static function primary(VaultItemType $type): ?string
    {
        foreach (self::fields($type) as $field) {
            if (($field['primary'] ?? false) === true) {
                return $field['key'];
            }
        }

        return null;
    }

    /** @return list<string> template fields that are encrypted */
    public static function secretKeys(VaultItemType $type): array
    {
        return array_values(array_map(fn (array $f) => $f['key'], array_filter(self::fields($type), fn (array $f) => $f['secret'])));
    }

    public static function isFingerprinted(VaultItemType $type, string $field): bool
    {
        return (self::byKey($type)[$field]['fingerprint'] ?? false) === true;
    }

    /** Custom-field secrets are stored under "custom.{id}". */
    public static function customKey(string $id): string
    {
        return 'custom.'.$id;
    }

    /** The label of one of the item's secret fields (template, notes or a secret custom field), or null when it isn't one. */
    public static function secretLabel(VaultItem $item, string $key): ?string
    {
        if ($key === self::NOTES) {
            return 'Notes';
        }

        if (str_starts_with($key, 'custom.')) {
            foreach ($item->custom_fields ?? [] as $custom) {
                if (self::customKey($custom['id']) === $key && $custom['secret']) {
                    return $custom['label'];
                }
            }

            return null;
        }

        $field = self::byKey($item->type)[$key] ?? null;

        return $field !== null && $field['secret'] ? $field['label'] : null;
    }

    /**
     * Every type with its fields, for the form.
     *
     * @return list<array{type: string, label: string, expiryLabel: string, fields: list<Field>}>
     */
    public static function all(): array
    {
        return array_map(fn (VaultItemType $type) => [
            'type' => $type->value,
            'label' => $type->label(),
            'expiryLabel' => self::expiryLabel($type),
            'fields' => self::fields($type),
        ], VaultItemType::cases());
    }
}
