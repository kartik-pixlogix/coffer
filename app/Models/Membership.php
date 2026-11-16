<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's place in one company (`company_user`): exactly one role per company.
 * Change roles through App\Actions\Companies\SetCompanyRole so spatie's team roles stay in sync.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CompanyRole $role
 * @property Carbon|null $last_active_at
 * @property Carbon|null $created_at
 * @property-read Company $company
 * @property-read User $user
 */
class Membership extends Pivot
{
    protected $table = 'company_user';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'last_active_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
