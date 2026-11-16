<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MoneyAccountKind;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where cash sits (spec §1): the company account (JV-level in a joint venture), one
 * account per JV side, and any extra bank or cash accounts.
 *
 * @property int $id
 * @property int|null $side_id
 * @property string $name
 * @property MoneyAccountKind $kind
 * @property string|null $bank_label
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property-read Side|null $side
 */
#[Fillable(['company_id', 'side_id', 'name', 'kind', 'bank_label', 'position', 'archived_at'])]
class MoneyAccount extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'kind' => MoneyAccountKind::class,
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Side, $this> */
    public function side(): BelongsTo
    {
        return $this->belongsTo(Side::class);
    }
}
