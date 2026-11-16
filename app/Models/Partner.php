<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyRole;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An owner with an equity share: of the company (Single) or of their side (JV).
 *
 * @property int $id
 * @property int|null $side_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $email
 * @property CompanyRole $role
 * @property int $colour 1–6, maps to --partner-N
 * @property int $equity_bps
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property-read Side|null $side
 * @property-read User|null $user
 * @property-read Collection<int, Member> $members
 */
#[Fillable(['company_id', 'side_id', 'user_id', 'name', 'email', 'role', 'colour', 'equity_bps', 'position', 'archived_at'])]
class Partner extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    public const int COLOURS = 6;

    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'colour' => 'integer',
            'equity_bps' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Side, $this> */
    public function side(): BelongsTo
    {
        return $this->belongsTo(Side::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Effective equity in bps (spec §1): Single = own share; JV = side share × share within side.
     */
    public function effectiveEquityBps(): int
    {
        if ($this->side_id === null) {
            return $this->equity_bps;
        }

        return intdiv($this->side->equity_bps * $this->equity_bps, 10000);
    }

    /** Partners with records (team members or anything in the books) can only be archived (spec §16). */
    public function hasRecords(): bool
    {
        return $this->members()->withTrashed()->exists()
            || JournalLine::query()->withoutGlobalScope('company')->where('partner_id', $this->id)->exists()
            || Income::query()->withoutGlobalScope('company')->withTrashed()->where(fn ($q) => $q->where('brought_in_by', $this->id)->orWhere('received_by', $this->id))->exists()
            || ExpensePayer::query()->withoutGlobalScope('company')->where('partner_id', $this->id)->exists();
    }
}
