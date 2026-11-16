<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Enums\CapitalMovementKind;
use App\Models\MoneyAccount;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->sharedMessages();
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
