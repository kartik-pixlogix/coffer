<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\MoneyAccount;
use Carbon\CarbonImmutable;

/**
 * Record repayment (PartnerLoans.dc.html modal, spec §12): amount (at most what is
 * outstanding, checked when posting), paid on, paid from and a note.
 */
final class RepaymentRequest extends MoneyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('action.capital.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_paise' => $this->amountRules(),
            'date' => $this->dateRules(),
            'money_account_id' => ['required', 'integer', $this->activeIn('money_accounts')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['amount_paise.required' => 'Enter the amount paid back.', 'amount_paise.min' => 'Enter the amount paid back.'] + $this->sharedMessages();
    }

    /** @return array{amount: int, date: CarbonImmutable, account: MoneyAccount, note: string|null} */
    public function repayment(): array
    {
        return [
            'amount' => $this->integer('amount_paise'),
            'date' => $this->dateValue(),
            'account' => $this->moneyAccount(),
            'note' => $this->optionalText('note'),
        ];
    }
}
