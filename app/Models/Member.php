<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A team member who logs hours and costs money, billed to one partner (spec §7).
 *
 * @property int $id
 * @property int $partner_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $email
 * @property CostType $cost_type
 * @property int $rate_paise per hour (Hourly) or per month (Fixed monthly)
 * @property MemberStatus $status
 * @property-read Partner $partner
 * @property-read User|null $user
 */
#[Fillable(['company_id', 'partner_id', 'user_id', 'name', 'designation', 'email', 'cost_type', 'rate_paise', 'status'])]
class Member extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<MemberFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'cost_type' => CostType::class,
            'status' => MemberStatus::class,
            'rate_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
