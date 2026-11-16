<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryKind;
use App\Models\Concerns\BelongsToCompany;
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
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 */
#[Fillable(['company_id', 'kind', 'name', 'system', 'position', 'archived_at'])]
class Category extends Model
{
    use BelongsToCompany;

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

    public function isSalary(): bool
    {
        return $this->system && $this->kind === CategoryKind::Expense;
    }
}
