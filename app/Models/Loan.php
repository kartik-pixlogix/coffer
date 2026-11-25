<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EncryptsFields;
use App\Models\Concerns\HasVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money a partner lent the company (spec §12, §4.2): received into a money account
 * (Dr account, Cr Loans payable) or converted from what the company owed them for
 * personal-card spending (Dr Partner current, Cr Loans payable). It closes on its own
 * when repayments reach the amount.
 *
 * @property int $id
 * @property int $number
 * @property int $partner_id
 * @property int $amount_paise
 * @property CarbonImmutable $lent_on
 * @property int|null $money_account_id
 * @property bool $converted
 * @property string|null $purpose
 * @property int|null $instalment_paise
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $closed_on
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read Partner $partner
 * @property-read MoneyAccount|null $moneyAccount
 * @property-read Collection<int, LoanRepayment> $repayments
 */
#[Fillable([
    'company_id', 'number', 'partner_id', 'amount_paise', 'lent_on', 'money_account_id', 'converted', 'purpose',
    'instalment_paise', 'due_on', 'closed_on', 'journal_entry_id', 'created_by',
])]
class Loan extends Model
{
    use Audited;
    use BelongsToCompany, SoftDeletes;
    use EncryptsFields;
    use HasVoucher;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount_paise' => 'integer',
            'lent_on' => 'immutable_date',
            'converted' => 'boolean',
            'instalment_paise' => 'integer',
            'due_on' => 'immutable_date',
            'closed_on' => 'immutable_date',
        ];
    }

    /** "L-01". */
    public function code(): string
    {
        return 'L-'.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /** @return HasMany<LoanRepayment, $this> */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function auditLabel(): string
    {
        return 'Loan '.$this->code();
    }
}
