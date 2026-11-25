<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryKind;
use App\Enums\ScheduleLine;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\FlushesReportCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An income or expense category (spec §4.1). "Salary" is the system expense category
 * that salary runs post to.
 *
 * @property int $id
 * @property CategoryKind $kind
 * @property string $name
 * @property bool $system
 * @property string|null $schedule_line the Schedule III line (D156); null means the default for its kind
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 */
#[Fillable(['company_id', 'kind', 'name', 'system', 'schedule_line', 'position', 'archived_at'])]
class Category extends Model
{
    use Audited;
    use BelongsToCompany;
    use FlushesReportCache;

    public const string SALARY = 'Salary';

    /** @var array<string, list<string>> */
    public const array DEFAULTS = [
        'income' => ['Project', 'Product', 'Consulting', 'Other'],
        'expense' => ['Salary', 'Software', 'Hardware', 'Travel', 'Marketing', 'Office & misc'],
    ];

    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'system' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /** Where it reports in the Schedule III statement of profit and loss (D156). */
    public function scheduleLine(): ScheduleLine
    {
        return ($this->schedule_line !== null ? ScheduleLine::tryFrom($this->schedule_line) : null)
            ?? ScheduleLine::defaultFor($this->kind, $this->name, $this->system);
    }

    public function isSalary(): bool
    {
        return $this->system && $this->kind === CategoryKind::Expense;
    }
}
