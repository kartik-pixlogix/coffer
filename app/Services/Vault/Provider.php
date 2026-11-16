<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultItemType;

/** One provider catalog entry (ProviderCatalog). */
final readonly class Provider
{
    /** @param list<array{label: string, secret: bool}> $customFields */
    public function __construct(
        public string $key,
        public string $name,
        public string $monogram,
        public string $category,
        public string $hint,
        public VaultItemType $type,
        public string $url,
        public string $fills,
        public array $customFields,
    ) {}

    /** @return array{key: string, name: string, monogram: string, category: string, hint: string, type: string, url: string, fills: string, customFields: list<array{label: string, secret: bool}>} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'monogram' => $this->monogram,
            'category' => $this->category,
            'hint' => $this->hint,
            'type' => $this->type->value,
            'url' => $this->url,
            'fills' => $this->fills,
            'customFields' => $this->customFields,
        ];
    }
}
