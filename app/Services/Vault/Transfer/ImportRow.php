<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use App\Enums\VaultItemType;
use SensitiveParameter;

/**
 * One item read from an import file, already shaped like SaveVaultItem's input (template
 * fields, secrets by key, custom fields). It holds plaintext secrets, so it lives only in
 * memory inside a request or the import job: never serialised, logged or put in a prop.
 */
final readonly class ImportRow
{
    /**
     * @param  array<string, string|bool|int>  $fields  non-secret template values
     * @param  array<string, string>  $secrets  template secret keys and "notes"
     * @param  list<array{label: string, secret: bool, value: string}>  $customFields
     * @param  list<string>  $tags
     * @param  list<string>  $notes  what was dropped or changed on the way in ("2FA key skipped")
     */
    public function __construct(
        public int $line,
        public VaultItemType $type,
        public string $name,
        public ?string $provider,
        public array $fields,
        #[SensitiveParameter] public array $secrets,
        #[SensitiveParameter] public array $customFields = [],
        public array $tags = [],
        public ?string $environment = null,
        public ?string $expiresAt = null,
        public bool $restricted = false,
        public array $notes = [],
    ) {}

    /** The main password, for duplicate fingerprints. */
    public function password(): ?string
    {
        $value = $this->secrets['password'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** "github.com" or the username, for the preview. */
    public function subtitle(): ?string
    {
        foreach (['username', 'user', 'host', 'domain'] as $key) {
            $value = $this->fields[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function url(): ?string
    {
        $value = $this->fields['url'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Never print secrets in a dump. @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['line' => $this->line, 'type' => $this->type->value, 'name' => $this->name, 'secrets' => array_keys($this->secrets)];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Import rows hold secrets and are never serialised.');
    }
}
