<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a journal entry: exactly one of debit or credit is above zero.
 * Dimensions (partner, side, member, project) drive attribution and settlements.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property CarbonImmutable $date
 * @property int $debit_paise
 * @property int $credit_paise
 * @property int|null $partner_id
 * @property int|null $side_id
 * @property int|null $member_id
 * @property int|null $project_id
 * @property-read Account $account
 * @property-read JournalEntry $entry
 */
#[Fillable(['company_id', 'journal_entry_id', 'account_id', 'date', 'debit_paise', 'credit_paise', 'partner_id', 'side_id', 'member_id', 'project_id'])]
class JournalLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'debit_paise' => 'integer',
            'credit_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
