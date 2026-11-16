<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "N items they could use → rotate these" (spec §14.2, D73): the items a person could
 * Use (or more) when they were removed from the company, deactivated, or lost vault
 * access through a role change. An item counts as done once it was rotated (or
 * deleted) after this was recorded.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $user_name
 * @property string $reason removed | role | deactivated
 * @property list<int> $item_ids
 * @property int|null $created_by
 * @property CarbonImmutable|null $dismissed_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['company_id', 'user_id', 'user_name', 'reason', 'item_ids', 'created_by', 'dismissed_at'])]
class VaultOffboarding extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'item_ids' => 'array',
            'dismissed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('dismissed_at');
    }

    /** @return Collection<int, VaultItem> the items still in the vault, with their vault */
    public function items(): Collection
    {
        return VaultItem::query()->with('vault')->whereIn('id', $this->item_ids)->orderBy('name')->get();
    }

    /** Rotated (or gone) since the person left. */
    public function isRotated(VaultItem $item): bool
    {
        return $item->rotated_at !== null && $item->rotated_at->gt($this->created_at);
    }

    /** "removed from the company", "lost vault access", "deactivated". */
    public function reasonText(): string
    {
        return match ($this->reason) {
            'role' => 'lost vault access',
            'deactivated' => 'was deactivated',
            default => 'was removed',
        };
    }
}
