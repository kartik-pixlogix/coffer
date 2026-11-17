<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use App\Enums\VaultAccessLevel;
use App\Models\User;
use App\Models\VaultItem;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\VaultAccess;
use Illuminate\Database\Eloquent\Collection;

/**
 * What goes into an encrypted vault export (D77): every item the exporter can Use in the
 * chosen vaults, in Coffer's own shape (the import's "coffer" format reads it back):
 * template fields, all secrets, custom fields with values, tags, environment, expiry and
 * "Admins only". Attachments are listed by name, not included.
 */
final readonly class VaultExporter
{
    public function __construct(private VaultAccess $access, private ItemSecrets $secrets) {}

    /**
     * @param  list<int>|null  $vaultIds  null = every vault
     * @return Collection<int, VaultItem>
     */
    public function items(User $user, ?array $vaultIds): Collection
    {
        return VaultItem::query()->with(['vault', 'attachments'])
            ->when($vaultIds !== null, fn ($q) => $q->whereIn('vault_id', $vaultIds ?? []))
            ->orderBy('vault_id')->orderBy('name')->get()
            ->filter(fn (VaultItem $item) => $this->access->can($user, $item, VaultAccessLevel::Use))
            ->values();
    }

    /**
     * The plaintext JSON that ExportCrypto seals. Only ever held in memory for one request.
     *
     * @param  Collection<int, VaultItem>  $items
     */
    public function payload(Collection $items): string
    {
        $out = [];

        foreach ($items as $item) {
            $secrets = $this->secrets->all($item);
            $custom = [];

            foreach ($item->custom_fields ?? [] as $c) {
                $custom[] = [
                    'label' => $c['label'],
                    'secret' => $c['secret'],
                    'value' => $c['secret'] ? ($secrets[ItemTemplates::customKey($c['id'])] ?? '') : ($c['value'] ?? ''),
                ];
            }

            $out[] = [
                'vault' => $item->vault->name,
                'type' => $item->type->value,
                'provider' => $item->provider,
                'name' => $item->name,
                'environment' => $item->environment?->value,
                'tags' => $item->tags ?? [],
                'expires_at' => $item->expires_at?->toDateString(),
                'restricted' => $item->restricted,
                'fields' => $item->fields ?? (object) [],
                'secrets' => (object) array_filter($secrets, fn (string $key) => ! str_starts_with($key, 'custom.'), ARRAY_FILTER_USE_KEY),
                'custom_fields' => $custom,
                'attachments' => $item->attachments->pluck('name')->values()->all(),
            ];
        }

        return json_encode(['items' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
