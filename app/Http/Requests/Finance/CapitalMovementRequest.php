<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\CapitalMovementKind;
use App\Ledger\Period;
use App\Ledger\Reports;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record contribution / Record drawing (CapitalAccounts.dc.html modal, spec §11):
 * partner, amount, date, the money account it went into or came out of, and a note.
 * Needs "Manage capital and loans".
 */
final class CapitalMovementRequest extends MoneyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('action.capital.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CapitalMovementKind::class)],
            'partner_id' => ['required', 'integer', $this->activeIn('partners')],
            'amount_paise' => $this->amountRules(),
            'date' => $this->dateRules(),
            'money_account_id' => ['required', 'integer', $this->activeIn('money_accounts')],
            'note' => ['nullable', 'string', 'max:500'],
            'confirm_overdraw' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->sharedMessages();
    }

    /**
     * F1 (D116): a drawing larger than the partner's capital (closing balance for its
     * fiscal year, profit share so far included) needs `confirm_overdraw` from the dialog.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->string('kind')->toString() !== CapitalMovementKind::Drawing->value || $this->boolean('confirm_overdraw')) {
                return;
            }

            $company = $this->company();
            $partner = $this->partner();
            $rows = app(Reports::class)->capitalStatement($company, Period::fiscalYearOf($company, $this->dateValue()));
            $capital = (int) (collect($rows)->firstWhere('partner_id', $partner->id)['closing'] ?? 0);

            if ($this->integer('amount_paise') > $capital) {
                $validator->errors()->add('confirm_overdraw', "This takes {$partner->name}’s capital below zero (it is ".Money::format($capital).' now). Tick “Record anyway” to go ahead.');
            }
        }];
    }

    /** @return array{kind: CapitalMovementKind, partner: Partner, amount: int, date: CarbonImmutable, account: MoneyAccount, note: string|null} */
    public function movement(): array
    {
        return [
            'kind' => CapitalMovementKind::from($this->string('kind')->toString()),
            'partner' => $this->partner(),
            'amount' => $this->integer('amount_paise'),
            'date' => $this->dateValue(),
            'account' => $this->moneyAccount(),
            'note' => $this->optionalText('note'),
        ];
    }
}
