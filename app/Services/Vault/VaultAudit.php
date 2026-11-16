<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Auth\DeviceLabel;
use App\Enums\VaultAuditAction;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAuditEvent;
use App\Models\VaultItem;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes the vault's append-only audit log (spec §14.4): actor, action, item (or a whole
 * vault, for vault sharing), field, IP and user agent. `meta` is for labels and ids only,
 * never a secret value.
 */
final readonly class VaultAudit
{
    public function __construct(private CurrentCompany $current, private ?Request $request = null) {}

    /** @param  array<string, scalar|null>  $meta */
    public function record(VaultAuditAction $action, ?User $user, VaultItem|Vault|null $item = null, ?string $field = null, array $meta = [], ?int $companyId = null): VaultAuditEvent
    {
        // The live request (controllers are reused between requests in tests and long-running workers).
        $request = app()->bound('request') ? app('request') : $this->request;
        $vault = $item instanceof Vault ? $item : null;
        $item = $item instanceof VaultItem ? $item : null;
        $company = $companyId ?? $item->company_id ?? $vault->company_id ?? $this->current->id();
        $fromHttp = $request instanceof Request && (! app()->runningInConsole() || app()->runningUnitTests());
        $agent = $fromHttp ? (string) $request->userAgent() : '';
        $device = $agent === '' ? null : DeviceLabel::from($agent);

        // "New device" (VaultAudit.dc.html): the first event from this person on this device label.
        $new = $user !== null && $device !== null && ! VaultAuditEvent::query()->withoutGlobalScope('company')
            ->where('company_id', $company)->where('user_id', $user->id)->where('device', $device)->exists();

        return VaultAuditEvent::query()->create([
            'company_id' => $company,
            'device' => $device,
            'new_device' => $new,
            'user_id' => $user?->id,
            'vault_id' => $item->vault_id ?? $vault?->id,
            'vault_item_id' => $item?->id,
            'item_name' => match (true) {
                $item !== null => Str::limit($item->name, 157),
                $vault !== null => Str::limit($vault->name.' vault', 157),
                default => null,
            },
            'action' => $action,
            'field' => $field,
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? Str::limit((string) $request->userAgent(), 509) : null,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }
}
