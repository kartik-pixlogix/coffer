<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EncryptsFields;
use App\Models\Concerns\HasVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A personal-card reimbursement paid out from a money account (spec §4.2): Dr Partner
 * current (a partner) or Reimbursements payable (a member), Cr the account.
 *
 * @property int $id
 * @property int|null $partner_id
 * @property int|null $member_id
 * @property int $amount_paise
 * @property CarbonImmutable $paid_on
 * @property int $money_account_id
 * @property string|null $note
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read Partner|null $partner
 * @property-read Member|null $member
 * @property-read MoneyAccount $moneyAccount
 */
#[Fillable(['company_id', 'partner_id', 'member_id', 'amount_paise', 'paid_on', 'money_account_id', 'note', 'journal_entry_id', 'created_by'])]
class Reimbursement extends Model
{
    use Audited;
    use BelongsToCompany, SoftDeletes;
    use EncryptsFields;
    use HasVoucher;

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'paid_on' => 'immutable_date',
        ];
    }

    public function payeeName(): string
    {
        return $this->partner->name ?? $this->member->name ?? '';
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function auditLabel(): string
    {
        return 'Reimbursement #'.$this->id;
    }
}
