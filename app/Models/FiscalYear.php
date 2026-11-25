<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A fiscal year (spec §1, §4.6): April–March by default. Once closed, nothing dated
 * inside it can be posted, edited or reversed until an Admin reopens it.
 *
 * @property int $id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $label
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property int|null $closing_entry_id
 */
#[Fillable(['company_id', 'starts_on', 'ends_on', 'label', 'closed_at', 'closed_by', 'closing_entry_id'])]
class FiscalYear extends Model
{
    use Audited;
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function auditLabel(): string
    {
        return 'Fiscal year '.$this->label;
    }

    /** @param array<string, mixed> $changes */
    public function auditAction(string $event, array $changes): ?string
    {
        if ($event !== 'updated' || ! isset($changes['closed_at'])) {
            return null;
        }

        return $this->closed_at === null ? 'reopened' : 'closed';
    }
}
