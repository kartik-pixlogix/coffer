<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultAccessLevel;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access to a whole vault (vault_id) or one item (vault_item_id) for a user, optionally
 * ending on a date (spec §14.2). Read through App\Services\Vault\VaultAccess.
 *
 * @property int $id
 * @property int|null $vault_id
 * @property int|null $vault_item_id
 * @property int $user_id
 * @property VaultAccessLevel $level
 * @property CarbonImmutable|null $expires_at
 * @property int|null $granted_by
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 */
#[Fillable(['company_id', 'vault_id', 'vault_item_id', 'user_id', 'level', 'expires_at', 'granted_by'])]
class VaultGrant extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'level' => VaultAccessLevel::class,
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Grants whose "Access ends" date hasn't passed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
