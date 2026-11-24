<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Enums\PayerMode;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EncryptsFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money spent (spec §10). Pending until a partner or Admin approves it; only approved
 * expenses post (spec §4.3). Salary runs appear as read-only Auto rows (D43).
 *
 * @property int $id
 * @property string $description
 * @property int $amount_paise
 * @property CarbonImmutable $spent_on
 * @property int $category_id
 * @property int|null $project_id
 * @property bool $billable
 * @property PayerMode $payer_mode
 * @property string|null $notes
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 * @property string|null $receipt_mime
 * @property int|null $receipt_size
 * @property ExpenseStatus $status
 * @property string|null $reject_reason
 * @property int|null $submitted_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property bool $auto
 * @property int|null $salary_run_id
 * @property int|null $journal_entry_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Category $category
 * @property-read Project|null $project
 * @property-read User|null $submitter
 * @property-read User|null $decider
 * @property-read Collection<int, ExpensePayer> $payers
 * @property-read SalaryRun|null $salaryRun
 */
#[Fillable([
    'company_id', 'description', 'amount_paise', 'spent_on', 'category_id', 'project_id', 'billable', 'payer_mode', 'notes',
    'receipt_path', 'receipt_name', 'receipt_mime', 'receipt_size', 'status', 'reject_reason', 'submitted_by', 'decided_by',
    'decided_at', 'auto', 'salary_run_id', 'journal_entry_id',
])]
class Expense extends Model
{
    use BelongsToCompany, SoftDeletes;
    use EncryptsFields;

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'spent_on' => 'immutable_date',
            'billable' => 'boolean',
            'payer_mode' => PayerMode::class,
            'receipt_size' => 'integer',
            'status' => ExpenseStatus::class,
            'decided_at' => 'immutable_datetime',
            'auto' => 'boolean',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return HasMany<ExpensePayer, $this> */
    public function payers(): HasMany
    {
        return $this->hasMany(ExpensePayer::class)->orderBy('position');
    }

    /** @return BelongsTo<SalaryRun, $this> */
    public function salaryRun(): BelongsTo
    {
        return $this->belongsTo(SalaryRun::class);
    }

    public function isPending(): bool
    {
        return $this->status === ExpenseStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }
}
