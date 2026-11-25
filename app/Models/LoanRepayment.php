<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EncryptsFields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money paid back to the partner who lent it (spec §12, §4.2): Dr Loans payable, Cr the
 * account it was paid from. Never more than what is outstanding on the loan.
 *
 * @property int $id
 * @property int $loan_id
 * @property int $amount_paise
 * @property CarbonImmutable $paid_on
 * @property int $money_account_id
 * @property string|null $note
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read Loan $loan
 * @property-read MoneyAccount $moneyAccount
 */
#[Fillable(['company_id', 'loan_id', 'amount_paise', 'paid_on', 'money_account_id', 'note', 'journal_entry_id', 'created_by'])]
class LoanRepayment extends Model
{
    use Audited;
    use BelongsToCompany, SoftDeletes;
    use EncryptsFields;

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'paid_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function auditLabel(): string
    {
        return "Repayment #{$this->id} of loan #{$this->loan_id}";
    }
}
