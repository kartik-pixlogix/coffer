<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project (spec §16): name, client and status. Timesheet entries, income and
 * expenses point at one; archived projects stay on old records but can't be picked.
 *
 * @property int $id
 * @property string $name
 * @property string|null $client
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['company_id', 'name', 'client', 'status'])]
class Project extends Model
{
    use BelongsToCompany;

    public const string INTERNAL = 'Internal';

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }

    /** @param Builder<Project> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ProjectStatus::Active->value);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** Referenced by a timesheet entry, a journal line, income or an expense, so it can only be archived. */
    public function inUse(): bool
    {
        return $this->timeEntries()->exists()
            || JournalLine::query()->where('project_id', $this->id)->exists()
            || Income::query()->withTrashed()->where('project_id', $this->id)->exists()
            || Expense::query()->withTrashed()->where('project_id', $this->id)->exists();
    }

    /** "Internal" is created with every company (spec §8.1). */
    public static function ensureDefaults(Company $company): void
    {
        self::query()->withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'name' => self::INTERNAL],
            ['status' => ProjectStatus::Active],
        );
    }
}
