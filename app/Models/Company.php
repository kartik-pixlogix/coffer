<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributionMode;
use App\Enums\CompanyRole;
use App\Enums\CompanyType;
use App\Models\Concerns\EncryptsFields;
use App\Models\Concerns\FlushesReportCache;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A set of books inside a workspace (spec §3).
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $short_code
 * @property CompanyType $type
 * @property string $currency
 * @property int $fy_start_month
 * @property int $working_days Bitmask, Monday = 1 … Sunday = 64 (spec §3.2)
 * @property AttributionMode $attribution_mode
 * @property int $opening_balance_paise
 * @property string|null $legal_name
 * @property string|null $gstin
 * @property string|null $address
 * @property int $hours_per_day_centi standard working day in hundredths of an hour (D63)
 * @property string|null $logo_path private-disk path of the logo
 * @property CarbonImmutable|null $archived_at archived (V4, D139): out of the switcher and the plan count, read-only
 * @property int|null $archived_by
 * @property CarbonImmutable|null $deletion_requested_at deletion asked for (V4, D140); the row is soft-deleted meanwhile
 * @property CarbonImmutable|null $deletion_scheduled_for erased by coffer:privacy-purge from this day
 * @property int|null $deletion_requested_by
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property-read Workspace $workspace
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, Side> $sides
 * @property-read Collection<int, Partner> $partners
 * @property-read Collection<int, Member> $members
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, Invite> $invites
 */
#[Fillable([
    'workspace_id', 'name', 'short_code', 'type', 'currency', 'fy_start_month', 'working_days',
    'attribution_mode', 'opening_balance_paise', 'legal_name', 'gstin', 'address', 'hours_per_day_centi',
])]
class Company extends Model
{
    use EncryptsFields;

    /** @use HasFactory<CompanyFactory> */
    use FlushesReportCache, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => CompanyType::class,
            'attribution_mode' => AttributionMode::class,
            'opening_balance_paise' => 'integer',
            'fy_start_month' => 'integer',
            'working_days' => 'integer',
            'hours_per_day_centi' => 'integer',
            'archived_at' => 'immutable_datetime',
            'deletion_requested_at' => 'immutable_datetime',
            'deletion_scheduled_for' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<User, $this, Membership, 'membership'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'last_active_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Side, $this> */
    public function sides(): HasMany
    {
        return $this->hasMany(Side::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Partner, $this> */
    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->orderBy('name');
    }

    /** @return HasMany<Invite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /** Archived (V4, D139): read-only, out of the switcher and the plan's company count. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isJv(): bool
    {
        return $this->type === CompanyType::Jv;
    }

    /** Two-letter tile on the switcher and avatars ("SP" for Saarthi Portal JV). */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn (string $w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: mb_strtoupper(mb_substr($this->short_code, 0, 2));
    }

    public function roleOf(User $user): ?CompanyRole
    {
        return $this->memberships()->where('user_id', $user->id)->first()?->role;
    }

    /** @return Collection<int, User> */
    public function admins(): Collection
    {
        return $this->users()->wherePivot('role', CompanyRole::Admin->value)->get();
    }
}
