<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's salary for a month (spec §8.3): billable hours × rate, or the fixed monthly
 * cost, posted as an Auto Salary expense on the month's last day. It is the source of
 * that journal entry; Phase 5 shows it as an Auto row in Expenses (D38).
 *
 * @property int $id
 * @property int $member_id
 * @property CarbonImmutable $month first day of the month
 * @property int $billable_centi
 * @property int $amount_paise
 * @property int|null $journal_entry_id
 * @property int|null $run_by
 * @property CarbonImmutable|null $posted_at
 * @property-read Member $member
 * @property-read JournalEntry|null $entry
 */
#[Fillable(['company_id', 'member_id', 'month', 'billable_centi', 'amount_paise', 'journal_entry_id', 'run_by', 'posted_at'])]
class SalaryRun extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasVoucher;

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'billable_centi' => 'integer',
            'amount_paise' => 'integer',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function auditLabel(): string
    {
        return 'Salary run '.$this->month->format('M Y').' (member #'.$this->member_id.')';
    }
}
