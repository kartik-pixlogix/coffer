<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\VaultAuditAction;
use App\Models\User;
use App\Models\VaultAuditEvent;
use App\Models\VaultItem;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes the vault's append-only audit log (spec §14.4): actor, action, item, field, IP
 * and user agent. `meta` is for labels and ids only, never a secret value. Part B's
 * audit page and sharing events read and write the same table.
 */
final readonly class VaultAudit
{
    public function __construct(private CurrentCompany $current, private ?Request $request = null) {}

    /** @param  array<string, scalar|null>  $meta */
    public function record(VaultAuditAction $action, ?User $user, ?VaultItem $item = null, ?string $field = null, array $meta = [], ?int $companyId = null): VaultAuditEvent
    {
        $request = $this->request ?? (app()->bound('request') ? app('request') : null);

        return VaultAuditEvent::query()->create([
            'company_id' => $companyId ?? $item->company_id ?? $this->current->id(),
            'user_id' => $user?->id,
            'vault_id' => $item?->vault_id,
            'vault_item_id' => $item?->id,
            'item_name' => $item === null ? null : Str::limit($item->name, 157),
            'action' => $action,
            'field' => $field,
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? Str::limit((string) $request->userAgent(), 509) : null,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
