<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Auth\DeviceLabel;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Models\Member;
use App\Models\Partner;
use App\Models\User;
use App\Models\VaultAttachment;
use App\Models\VaultAuditEvent;
use App\Models\VaultFavourite;
use App\Models\VaultItem;
use App\Models\VaultItemVersion;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds every vault response field by field (D66): metadata, which secrets are set,
 * levels and people — never a secret value or ciphertext. Reveal, copy and one-time
 * codes are the only way a value leaves, one field at a time.
 */
final class ItemPresenter
{
    /** @var array<int, int|null>|null user id → partner colour (1–6) */
    private ?array $colours = null;

    public function __construct(private readonly VaultAccess $access, private readonly ItemSecrets $secrets) {}

    /**
     * @param  Collection<int, VaultItem>  $items  from VaultAccess::visibleItems
     * @param  array<int, list<int>>  $reused
     * @return list<array<string, mixed>>
     */
    public function rows(User $user, Collection $items, array $reused = []): array
    {
        $favourites = VaultFavourite::query()->where('user_id', $user->id)->pluck('vault_item_id')->flip();

        return array_values($items->map(fn (VaultItem $item) => $this->row($user, $item, $favourites->has($item->id), isset($reused[$item->id])))->all());
    }

    /** @return array<string, mixed> */
    public function row(User $user, VaultItem $item, bool $favourite, bool $reused = false): array
    {
        /** @var VaultAccessLevel|null $level */
        $level = $item->getAttribute('level') ?? $this->access->itemLevel($user, $item);
        $days = $item->expires_at === null ? null : (int) now()->startOfDay()->diffInDays($item->expires_at, false);
        $people = $this->people($item);

        return [
            'id' => $item->id,
            'vaultId' => $item->vault_id,
            'vault' => $item->vault->name,
            'name' => $item->name,
            'monogram' => $item->monogram(),
            'type' => $item->type->value,
            'typeLabel' => $item->type->label(),
            'badge' => $item->type->badge(),
            'provider' => $item->provider,
            'category' => ProviderCatalog::categoryOf($item->provider) ?? $item->type->label(),
            'subtitle' => $item->subtitle,
            'url' => $item->url,
            'environment' => $item->environment?->value,
            'tags' => $item->tags ?? [],
            'strength' => $item->strength,
            'strengthLabel' => PasswordStrength::label($item->strength),
            'expiresOn' => $item->expires_at?->toDateString(),
            'expiresInDays' => $days,
            'rotatedAt' => $item->rotated_at?->toIso8601String(),
            'favourite' => $favourite,
            'hasTotp' => $item->has_totp,
            'restricted' => $item->restricted,
            'breached' => $item->breached,
            'reused' => $reused,
            'old' => $item->strength !== null && $item->rotated_at !== null && $item->rotated_at->lt(now()->subDays(VaultHealth::OLD_DAYS)),
            'sharedWithMe' => $this->access->isSharedWith($user, $item),
            'level' => $level?->value,
            'people' => array_map(fn (array $p) => $this->person($p['user'], $p['level'], $p['via'], $user), $people),
        ];
    }

    /**
     * The drawer (Vault.dc.html): non-secret fields, which secrets are set, access and activity.
     *
     * @return array<string, mixed>
     */
    public function detail(User $user, VaultItem $item): array
    {
        $level = $this->access->itemLevel($user, $item) ?? VaultAccessLevel::View;
        $set = array_flip($this->secrets->keys($item));
        $favourite = VaultFavourite::query()->where('user_id', $user->id)->where('vault_item_id', $item->id)->exists();

        $fields = [];

        foreach (ItemTemplates::fields($item->type) as $field) {
            $fields[] = [
                'key' => $field['key'],
                'label' => $field['label'],
                'kind' => $field['kind'],
                'secret' => $field['secret'],
                'primary' => ($field['primary'] ?? false) === true,
                'value' => $field['secret'] ? null : $this->display($item->fields[$field['key']] ?? null, $field['kind']),
                'set' => $field['secret'] ? isset($set[$field['key']]) : ($item->field($field['key']) !== null || $field['kind'] === 'toggle'),
            ];
        }

        $custom = array_map(fn (array $c) => [
            'id' => $c['id'],
            'label' => $c['label'],
            'secret' => $c['secret'],
            'value' => $c['secret'] ? null : ($c['value'] ?? null),
            'set' => $c['secret'] ? isset($set[ItemTemplates::customKey($c['id'])]) : ($c['value'] ?? '') !== '',
        ], $item->custom_fields ?? []);

        $events = VaultAuditEvent::query()->with('user:id,name')
            ->where('vault_item_id', $item->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(6)->get();

        return [
            ...$this->row($user, $item, $favourite),
            'fields' => $fields,
            'customFields' => $custom,
            'notesSet' => isset($set[ItemTemplates::NOTES]),
            'primary' => ItemTemplates::primary($item->type),
            'expiryLabel' => ItemTemplates::expiryLabel($item->type),
            'attachments' => $item->attachments()->orderBy('id')->get()->map(fn (VaultAttachment $a) => [
                'id' => $a->id, 'name' => $a->name, 'size' => $a->size, 'mime' => $a->mime,
            ])->values()->all(),
            'activity' => $events->map(fn (VaultAuditEvent $e) => $this->event($e, $user))->values()->all(),
            'version' => $item->version,
            'createdBy' => $item->creator?->name,
            'abilities' => [
                'reveal' => $level->atLeast(VaultAccessLevel::Use),
                'edit' => $level->atLeast(VaultAccessLevel::Edit),
                'delete' => $level->atLeast(VaultAccessLevel::Manage),
                // Part B's share drawer.
                'share' => $level->atLeast(VaultAccessLevel::Manage),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function versions(VaultItem $item): array
    {
        return array_values(VaultItemVersion::query()->with('author:id,name')
            ->where('vault_item_id', $item->id)->orderByDesc('version')->limit(50)->get()
            ->map(fn (VaultItemVersion $v) => [
                'version' => $v->version,
                'reason' => $v->reason,
                'restoredFrom' => $v->restored_from,
                'by' => $v->author?->name,
                'at' => $v->created_at?->toIso8601String(),
                'current' => $v->version === $item->version,
            ])->all());
    }

    /**
     * "Rohan Desai copied the password" · "14 Nov 2026, 18:42 · Chrome on macOS · 203.0.113.77".
     *
     * @return array<string, mixed>
     */
    public function event(VaultAuditEvent $event, User $viewer): array
    {
        $who = $event->user === null ? 'Someone' : ($event->user->id === $viewer->id ? 'You' : $event->user->name);
        $what = $event->field === null ? 'this item' : 'the '.$this->fieldLabel($event);

        $text = match ($event->action) {
            VaultAuditAction::Created => "{$who} created this item",
            VaultAuditAction::Edited => "{$who} edited this item",
            VaultAuditAction::Rotated => "{$who} rotated {$what}",
            VaultAuditAction::Restored => "{$who} restored version ".(is_scalar($event->meta['restored_from'] ?? null) ? (string) $event->meta['restored_from'] : ''),
            VaultAuditAction::UsedCode => "{$who} used a one-time code",
            VaultAuditAction::Downloaded => "{$who} downloaded ".(is_scalar($event->meta['file'] ?? null) ? (string) $event->meta['file'] : 'an attachment'),
            default => "{$who} {$event->action->verb()} {$what}",
        };

        return [
            'id' => $event->id,
            'action' => $event->action->value,
            'text' => trim($text),
            'at' => $event->created_at->toIso8601String(),
            'device' => $event->user_agent === null ? null : DeviceLabel::from($event->user_agent),
            'ip' => $event->ip,
            'strong' => in_array($event->action, [VaultAuditAction::Revealed, VaultAuditAction::Copied, VaultAuditAction::UsedCode, VaultAuditAction::Downloaded], true),
        ];
    }

    /** @return list<array{user: User, level: VaultAccessLevel, via: string}> */
    private function people(VaultItem $item): array
    {
        return $this->access->peopleWithAccess($item);
    }

    /** @return array<string, mixed> */
    private function person(User $user, VaultAccessLevel $level, string $via, User $viewer): array
    {
        $this->colours ??= $this->colourMap();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'you' => $user->id === $viewer->id,
            'level' => $level->value,
            'via' => $via,
            'colour' => $this->colours[$user->id] ?? null,
        ];
    }

    /** @return array<int, int|null> */
    private function colourMap(): array
    {
        $map = [];

        foreach (Partner::query()->whereNotNull('user_id')->get(['user_id', 'colour']) as $partner) {
            $map[(int) $partner->user_id] = $partner->colour;
        }

        foreach (Member::query()->with('partner:id,colour')->whereNotNull('user_id')->get(['user_id', 'partner_id']) as $member) {
            $map[(int) $member->user_id] ??= $member->partner->colour;
        }

        return $map;
    }

    private function fieldLabel(VaultAuditEvent $event): string
    {
        $field = (string) $event->field;

        if ($field === ItemTemplates::NOTES) {
            return 'notes';
        }

        $label = is_scalar($event->meta['label'] ?? null) ? (string) $event->meta['label'] : $field;

        return mb_strtolower($label);
    }

    private function display(mixed $value, string $kind): ?string
    {
        if ($kind === 'toggle') {
            return $value === true ? 'On' : 'Off';
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
