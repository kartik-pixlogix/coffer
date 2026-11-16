<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hours a member logged on a day (spec §8.1). `hours_centi` is hundredths of an hour
 * (D35). Entries logged by month or imported together share a `batch`.
 *
 * @property int $id
 * @property int $member_id
 * @property int $partner_id
 * @property int $project_id
 * @property CarbonImmutable $date
 * @property int $hours_centi
 * @property bool $billable
 * @property string|null $notes
 * @property string|null $batch
 * @property int|null $import_id
 * @property int|null $created_by
 * @property-read Member $member
 * @property-read Partner $partner
 * @property-read Project $project
 */
#[Fillable(['company_id', 'member_id', 'partner_id', 'project_id', 'date', 'hours_centi', 'billable', 'notes', 'batch', 'import_id', 'created_by'])]
class TimeEntry extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'hours_centi' => 'integer',
            'billable' => 'boolean',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
