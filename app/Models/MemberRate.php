<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a member's rate history (spec §7): from `effective_from` the member costs
 * `rate_paise` per hour (Hourly) or per month (Fixed monthly), until the next row.
 *
 * @property int $id
 * @property int $member_id
 * @property CostType $cost_type
 * @property int $rate_paise
 * @property CarbonImmutable $effective_from
 * @property int|null $created_by
 * @property-read Member $member
 * @property-read User|null $creator
 */
#[Fillable(['member_id', 'cost_type', 'rate_paise', 'effective_from', 'created_by'])]
class MemberRate extends Model
{
    protected function casts(): array
    {
        return [
            'cost_type' => CostType::class,
            'rate_paise' => 'integer',
            'effective_from' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
