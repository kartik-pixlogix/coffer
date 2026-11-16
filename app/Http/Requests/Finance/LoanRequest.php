<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Actions\Loans\LoanData;
use Carbon\CarbonImmutable;

/**
 * Record loan (spec §12): the lending partner, amount, date, and where it came from:
 * `account` (new money received into a money account) or `card` (what the company owes
 * the partner for personal-card spending, converted into a loan, spec §4.2). Optional:
 * what it's for, a monthly instalment and a date it's due by. Needs "Manage capital
 * and loans". Expenses' "Convert to loan" posts here too.
 */
final class LoanRequest extends MoneyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('action.capital.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'partner_id' => ['required', 'integer', $this->activeIn('partners')],
            'source' => ['required', 'in:account,card'],
            'amount_paise' => $this->amountRules(),
            'date' => $this->dateRules(),
            'money_account_id' => ['required_if:source,account', 'nullable', 'integer', $this->activeIn('money_accounts')],
            'purpose' => ['nullable', 'string', 'max:200'],
            'instalment_paise' => ['nullable', 'integer', 'min:1', 'lte:amount_paise'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->sharedMessages() + [
            'source.in' => 'Pick where the money came from.',
            'money_account_id.required_if' => 'Pick the account it went into.',
            'instalment_paise.min' => 'Enter an instalment above ₹0, or leave it empty.',
            'instalment_paise.lte' => 'The instalment can’t be more than the loan.',
            'due_on.after_or_equal' => 'The due date can’t be before the loan.',
            'due_on.date_format' => 'Pick a date.',
        ];
    }

    public function loanData(): LoanData
    {
        return new LoanData(
            partner: $this->partner(),
            amountPaise: $this->integer('amount_paise'),
            lentOn: $this->dateValue(),
            into: $this->input('source') === 'account' ? $this->moneyAccount() : null,
            purpose: $this->optionalText('purpose'),
            instalmentPaise: $this->filled('instalment_paise') ? $this->integer('instalment_paise') : null,
            dueOn: $this->filled('due_on') ? (CarbonImmutable::createFromFormat('!Y-m-d', $this->string('due_on')->toString()) ?: null) : null,
        );
    }
}
