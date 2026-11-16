<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money that came in (spec §9): from a source, into a money account or to a partner
 * personally, brought in by a partner. The source of one posted journal entry.
 *
 * @property int $id
 * @property string $source
 * @property int $amount_paise
 * @property CarbonImmutable $received_on
 * @property int $category_id
 * @property int|null $brought_in_by
 * @property int|null $money_account_id
 * @property int|null $received_by
 * @property int|null $project_id
 * @property string|null $notes
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read Category $category
 * @property-read Partner|null $broughtInBy
 * @property-read Partner|null $receivedBy
 * @property-read MoneyAccount|null $moneyAccount
 * @property-read Project|null $project
 * @property-read JournalEntry|null $entry
 */
#[Fillable([
    'company_id', 'source', 'amount_paise', 'received_on', 'category_id', 'brought_in_by', 'money_account_id',
    'received_by', 'project_id', 'notes', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    'journal_entry_id', 'created_by',
])]
class Income extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'received_on' => 'immutable_date',
            'attachment_size' => 'integer',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function broughtInBy(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'brought_in_by');
    }

    /** @return BelongsTo<Partner, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'received_by');
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
