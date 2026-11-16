<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Ask an Admin for access" from the permission-denied page (StateDenied).
 *
 * @property int $id
 * @property int $user_id
 * @property string $page
 * @property string|null $reason
 * @property CarbonImmutable|null $cancelled_at
 * @property-read User $user
 */
#[Fillable(['company_id', 'user_id', 'page', 'reason', 'cancelled_at'])]
class AccessRequest extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
