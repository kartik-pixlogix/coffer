<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultAccessDuration;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultRequestStatus;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone asking for access to a vault or one item, with a reason (spec §14.2). A Manager
 * of the target (or an Admin) approves it, which writes a grant, or denies it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int|null $vault_id
 * @property int|null $vault_item_id
 * @property VaultAccessLevel $level
 * @property VaultAccessDuration $duration
 * @property string $reason
 * @property VaultRequestStatus $status
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read User|null $decider
 * @property-read Vault|null $vault
 * @property-read VaultItem|null $item
 */
#[Fillable(['company_id', 'user_id', 'vault_id', 'vault_item_id', 'level', 'duration', 'reason', 'status', 'decided_by', 'decided_at', 'decision_note'])]
class VaultAccessRequest extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'level' => VaultAccessLevel::class,
            'duration' => VaultAccessDuration::class,
            'status' => VaultRequestStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return BelongsTo<VaultItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(VaultItem::class, 'vault_item_id');
    }

    /** "AWS — root account" or "Internal vault". */
    public function targetName(): string
    {
        return $this->item !== null ? $this->item->name : ($this->vault->name ?? 'Vault').' vault';
    }
}
