<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Ledger\Period;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EncryptsFields;
use Carbon\CarbonImmutable;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A team member who logs hours and costs money, billed to one partner (spec §7).
 * `cost_type` and `rate_paise` mirror the rate in effect today; the history in
 * `rates` is what earnings and salaries use.
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
 * @property CarbonImmutable|null $deactivated_on
 * @property CarbonImmutable|null $created_at
 * @property-read Partner $partner
 * @property-read User|null $user
 * @property-read Collection<int, MemberRate> $rates
 * @property-read Collection<int, TimeEntry> $timeEntries
 */
#[Fillable(['company_id', 'partner_id', 'user_id', 'name', 'designation', 'email', 'cost_type', 'rate_paise', 'status', 'deactivated_on'])]
class Member extends Model
{
    use Audited;
    use BelongsToCompany;
    use EncryptsFields;

    /** @use HasFactory<MemberFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // Every member starts with one rate, from the start of the fiscal year they join in,
        // so hours logged or imported for earlier this year still have a rate.
        static::created(function (Member $member): void {
            /** @var Company $company */
            $company = Company::query()->findOrFail($member->company_id);
            $from = Period::fiscalYearOf($company, $member->created_at ?? CarbonImmutable::today())->from;

            $member->rates()->create([
                'cost_type' => $member->cost_type,
                'rate_paise' => $member->rate_paise,
                'effective_from' => $from,
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'cost_type' => CostType::class,
            'status' => MemberStatus::class,
            'rate_paise' => 'integer',
            'deactivated_on' => 'immutable_date',
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

    /** @return HasMany<MemberRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(MemberRate::class)->orderBy('effective_from');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }

    /** Hours or salary on record: such members can be deactivated but not deleted (spec §7). */
    public function hasHistory(): bool
    {
        return $this->timeEntries()->exists()
            || JournalLine::query()->where('member_id', $this->id)->exists();
    }
}
