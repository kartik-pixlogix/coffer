<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vault in a company ("Internal", "Saarthi Portal JV", "Asha Retail", spec §14.1).
 * Shared vaults are reached through grants; a personal one only by its owner (D68).
 *
 * @property int $id
 * @property string $name
 * @property VaultType $type
 * @property int|null $owner_id
 * @property string|null $description
 * @property int $position
 * @property int|null $created_by
 * @property-read Collection<int, VaultItem> $items
 * @property-read Collection<int, VaultGrant> $grants
 * @property-read User|null $owner
 */
#[Fillable(['company_id', 'name', 'type', 'owner_id', 'description', 'position', 'created_by'])]
class Vault extends Model
{
    use BelongsToCompany;

    protected $attributes = ['type' => 'shared'];

    protected function casts(): array
    {
        return [
            'type' => VaultType::class,
            'position' => 'integer',
        ];
    }

    public function isPersonal(): bool
    {
        return $this->type === VaultType::Personal;
    }

    /** @return HasMany<VaultItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VaultItem::class);
    }

    /** @return HasMany<VaultGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(VaultGrant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
