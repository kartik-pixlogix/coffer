<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An encrypted snapshot of an item after a save (spec §14.4 "Version history"): its
 * metadata and every secret, sealed as one JSON document.
 *
 * @property int $id
 * @property int $company_id
 * @property int $vault_item_id
 * @property int $version
 * @property string $reason created | edited | rotated | restored
 * @property int|null $restored_from
 * @property string $snapshot
 * @property int $key_version
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $author
 */
#[Fillable(['company_id', 'vault_item_id', 'version', 'reason', 'restored_from', 'snapshot', 'key_version', 'created_by', 'created_at'])]
#[Hidden(['snapshot'])]
class VaultItemVersion extends Model
{
    use BelongsToCompany;

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return ['version' => 'integer', 'restored_from' => 'integer', 'key_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public static function aad(int $itemId, int $version): string
    {
        return "vault_version:{$itemId}:{$version}";
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
