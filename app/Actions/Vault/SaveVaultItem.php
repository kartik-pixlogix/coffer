<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultAuditAction;
use App\Enums\VaultEnvironment;
use App\Enums\VaultItemType;
use App\Models\User;
use App\Models\VaultAttachment;
use App\Models\VaultItem;
use App\Services\Vault\ItemAttachments;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\ItemVersions;
use App\Services\Vault\PasswordStrength;
use App\Services\Vault\Totp;
use App\Services\Vault\VaultAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Create or edit a vault item (spec §14.4). Template values are classified by
 * ItemTemplates: secret ones go to ItemSecrets (encrypted, fingerprinted when they are
 * passwords), the rest stay as searchable metadata. Every save that changes something
 * bumps the version, seals a snapshot, writes a vault audit event (Created, Edited, or
 * Rotated when the main secret changed) and an activity-log row naming only the
 * non-secret attributes that changed.
 */
final readonly class SaveVaultItem
{
    public function __construct(
        private ItemSecrets $secrets,
        private ItemAttachments $attachments,
        private ItemVersions $versions,
        private VaultAudit $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated VaultItemRequest input
     * @param  list<UploadedFile>  $files
     */
    public function handle(User $user, #[SensitiveParameter] array $data, ?VaultItem $item = null, array $files = [], bool $mayRestrict = false): VaultItem
    {
        return DB::transaction(function () use ($user, $data, $item, $files, $mayRestrict): VaultItem {
            $creating = $item === null;
            $item ??= new VaultItem(['created_by' => $user->id, 'version' => 1]);
            $type = VaultItemType::from((string) $data['type']);
            $templates = ItemTemplates::byKey($type);

            $fields = [];

            foreach ($templates as $key => $field) {
                if ($field['secret']) {
                    continue;
                }

                $value = ($data['fields'] ?? [])[$key] ?? null;

                if ($field['kind'] === 'toggle') {
                    $fields[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                } elseif ($value !== null && trim((string) $value) !== '') {
                    $fields[$key] = $field['kind'] === 'number' ? (int) $value : trim((string) $value);
                }
            }

            [$customFields, $customSecrets] = $this->customFields($item, $data['custom_fields'] ?? []);

            $item->fill([
                'vault_id' => (int) $data['vault_id'],
                'type' => $type,
                'provider' => $data['provider'] ?? null,
                'name' => trim((string) $data['name']),
                'environment' => isset($data['environment']) ? VaultEnvironment::from((string) $data['environment']) : null,
                'fields' => $fields === [] ? null : $fields,
                'custom_fields' => $customFields === [] ? null : $customFields,
                'tags' => $this->tags($data['tags'] ?? []),
                'expires_at' => $data['expires_at'] ?? null,
                'username' => isset($fields['username']) ? (string) $fields['username'] : (isset($fields['user']) ? (string) $fields['user'] : null),
                'url' => isset($fields['url']) ? (string) $fields['url'] : null,
                'subtitle' => self::subtitle($type, $fields),
                'updated_by' => $user->id,
            ]);

            if ($mayRestrict && array_key_exists('restricted', $data)) {
                $item->restricted = (bool) $data['restricted'];
            }

            $metaChanged = $creating ? [] : array_keys($item->getDirty());
            $item->save();

            // Secrets: absent or empty keeps the stored value; `clear` removes it.
            $changedSecrets = [];
            $submitted = (array) ($data['secrets'] ?? []);

            if (isset($submitted['totp']) && is_string($submitted['totp']) && $submitted['totp'] !== '') {
                $submitted['totp'] = (string) Totp::normalise($submitted['totp']);
            }

            foreach ([...ItemTemplates::secretKeys($type), ItemTemplates::NOTES] as $key) {
                $value = $submitted[$key] ?? null;

                if (in_array($key, $data['clear'] ?? [], true)) {
                    $value = null;
                } elseif (! is_string($value) || $value === '') {
                    continue;
                }

                if ($this->secrets->put($item, $key, $value, ItemTemplates::isFingerprinted($type, $key))) {
                    $changedSecrets[] = $key;
                }
            }

            // Secret fields of a type the item no longer has (a type change) go.
            $known = [...ItemTemplates::secretKeys($type), ItemTemplates::NOTES, ...array_keys($customSecrets)];

            foreach ($this->secrets->keys($item) as $key) {
                if (! in_array($key, $known, true) && ! str_starts_with($key, 'custom.')) {
                    $this->secrets->put($item, $key, null);
                    $changedSecrets[] = $key;
                }
            }

            foreach ($customSecrets as $key => $value) {
                if ($value !== null && $this->secrets->put($item, $key, $value)) {
                    $changedSecrets[] = $key;
                }
            }

            foreach ($this->secrets->keys($item) as $key) {
                if (str_starts_with($key, 'custom.') && ! array_key_exists($key, $customSecrets)) {
                    $this->secrets->put($item, $key, null);
                    $changedSecrets[] = $key;
                }
            }

            $attachmentsChanged = $this->syncAttachments($item, $user, $files, $data['remove_attachments'] ?? []);

            $primary = ItemTemplates::primary($type);
            $rotated = $primary !== null && in_array($primary, $changedSecrets, true);
            $primaryValue = $primary === null ? null : $this->secrets->get($item, $primary);
            $scored = $primary !== null && ItemTemplates::isFingerprinted($type, $primary);

            if ($rotated || $creating) {
                $item->rotated_at = now();
                $item->strength = $scored && $primaryValue !== null
                    ? (isset($data['strength']) ? (int) $data['strength'] : PasswordStrength::estimate($primaryValue))
                    : null;
                $item->breached = false;
                $item->breach_checked_at = null;
            }

            $item->has_totp = $this->secrets->get($item, 'totp') !== null;

            $changed = $creating || $metaChanged !== [] || $changedSecrets !== [] || $attachmentsChanged || $item->isDirty();

            if (! $changed) {
                return $item;
            }

            if (! $creating) {
                $item->version++;
            }

            $item->save();
            $this->versions->snapshot($item, $creating ? 'created' : ($rotated ? 'rotated' : 'edited'), $user);

            $action = $creating ? VaultAuditAction::Created : ($rotated ? VaultAuditAction::Rotated : VaultAuditAction::Edited);
            $this->audit->record($action, $user, $item, $rotated ? $primary : null, ['version' => $item->version]);

            activity('vault')->performedOn($item)->causedBy($user)
                ->withProperties([
                    'vault_id' => $item->vault_id,
                    'name' => $item->name,
                    'type' => $item->type->value,
                    'changed' => array_values(array_diff($metaChanged, ['updated_by', 'updated_at', 'subtitle'])),
                    // Names of secret fields only — never their values.
                    'secret_fields_changed' => array_values(array_unique($changedSecrets)),
                ])
                ->log($creating ? 'Vault item created' : 'Vault item edited');

            return $item;
        });
    }

    /**
     * The list's second line, from non-secret fields: "root@203.0.113.24:22".
     *
     * @param  array<string, mixed>  $f
     */
    public static function subtitle(VaultItemType $type, array $f): ?string
    {
        $s = fn (string $key): ?string => isset($f[$key]) && (string) $f[$key] !== '' ? (string) $f[$key] : null;
        $host = fn (?string $url): ?string => $url === null ? null : (parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST) ?: $url);
        $at = fn (?string $user, ?string $server, ?string $port): ?string => $server === null ? $user
            : ($user !== null ? $user.'@' : '').$server.($port !== null ? ':'.$port : '');

        $line = match ($type) {
            VaultItemType::Server => $at($s('user'), $s('host'), $s('port')),
            VaultItemType::Database => $at($s('user'), $s('host'), $s('port')) ?? $s('database'),
            VaultItemType::Domain => $s('registrar') !== null ? 'Registrar: '.$s('registrar') : $s('domain'),
            VaultItemType::Payment => trim(implode(' · ', array_filter([$s('mode'), $s('publishable_key')]))) ?: null,
            VaultItemType::ApiKey => $s('scopes') ?? $host($s('url')),
            VaultItemType::Note => null,
            default => $s('username') ?? $host($s('url')),
        };

        return $line === null ? null : Str::limit($line, 250);
    }

    /**
     * @return list<string>|null
     */
    private function tags(mixed $tags): ?array
    {
        $out = [];

        foreach ((array) $tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag));

            if ($tag !== '' && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Custom fields: definitions (and plain values) stay on the item; secret values go to
     * "custom.{id}". An empty secret value keeps what is stored.
     *
     * @return array{0: list<array{id: string, label: string, secret: bool, value?: string}>, 1: array<string, string|null>}
     */
    private function customFields(VaultItem $item, mixed $submitted): array
    {
        $existing = collect($item->custom_fields ?? [])->keyBy('id');
        $defs = [];
        $secrets = [];

        foreach ((array) $submitted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = is_string($row['id'] ?? null) && $existing->has($row['id']) ? $row['id'] : Str::lower(Str::random(10));
            $secret = filter_var($row['secret'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $value = is_string($row['value'] ?? null) ? $row['value'] : null;
            $def = ['id' => $id, 'label' => trim((string) $row['label']), 'secret' => $secret];

            if ($secret) {
                // Keep the stored secret when no new value was typed (null = keep).
                $secrets[ItemTemplates::customKey($id)] = $value === null || $value === '' ? null : $value;
            } elseif ($value !== null && $value !== '') {
                $def['value'] = $value;
            }

            $defs[] = $def;
        }

        return [$defs, $secrets];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function syncAttachments(VaultItem $item, User $user, array $files, mixed $remove): bool
    {
        $changed = false;

        foreach ((array) $remove as $id) {
            $attachment = VaultAttachment::query()->where('vault_item_id', $item->id)->whereKey((int) $id)->first();

            if ($attachment !== null) {
                $this->attachments->delete($attachment);
                $changed = true;
            }
        }

        foreach ($files as $file) {
            $this->attachments->store($item, $file, $user);
            $changed = true;
        }

        return $changed;
    }
}
