<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayerType;
use App\Ledger\PayerLine;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One payer of an expense (spec §10 "Paid by"): a partner, a member or the company, paid
 * from a money account or (no account) a personal card to be reimbursed.
 *
 * @property int $id
 * @property int $expense_id
 * @property int $position
 * @property PayerType $payer_type
 * @property int|null $partner_id
 * @property int|null $member_id
 * @property int|null $money_account_id
 * @property int $amount_paise
 * @property-read Partner|null $partner
 * @property-read Member|null $member
 * @property-read MoneyAccount|null $moneyAccount
 */
#[Fillable(['company_id', 'expense_id', 'position', 'payer_type', 'partner_id', 'member_id', 'money_account_id', 'amount_paise'])]
class ExpensePayer extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payer_type' => PayerType::class,
            'amount_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function isPersonal(): bool
    {
        return $this->money_account_id === null;
    }

    /** The ledger's view of this line (spec §4.2). */
    public function toPayerLine(): PayerLine
    {
        $from = $this->money_account_id !== null
            ? MoneyAccount::query()->withoutGlobalScope('company')->findOrFail($this->money_account_id)
            : null;

        return match ($this->payer_type) {
            PayerType::Partner => PayerLine::partner($this->amount_paise, Partner::query()->withoutGlobalScope('company')->findOrFail($this->partner_id), $from),
            PayerType::Member => PayerLine::member($this->amount_paise, Member::query()->withoutGlobalScope('company')->withTrashed()->findOrFail($this->member_id), $from),
            PayerType::Company => PayerLine::company($this->amount_paise, $from ?? throw new LogicException('The company pays from an account.')),
        };
    }
}
