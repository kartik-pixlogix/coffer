<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultAuditAction;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One vault audit event (spec §14.4): who did what to which item, from where. Append-only:
 * updates and deletes throw (spec §23 "vault events are immutable"). Written only by
 * App\Services\Vault\VaultAudit; `meta` never holds secret values.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property int|null $vault_id
 * @property int|null $vault_item_id
 * @property string|null $item_name
 * @property VaultAuditAction $action
 * @property string|null $field
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
#[Fillable(['company_id', 'user_id', 'vault_id', 'vault_item_id', 'item_name', 'action', 'field', 'ip', 'user_agent', 'meta', 'created_at'])]
class VaultAuditEvent extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Vault audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Vault audit events are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'action' => VaultAuditAction::class,
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
