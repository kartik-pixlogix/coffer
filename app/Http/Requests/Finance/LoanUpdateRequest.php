<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Actions\Loans\LoanEdit;
use App\Models\Loan;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Edit loan (A6, D138): what it's for and the repayment terms can always change; the
 * amount and date only while nothing has been repaid (RecordLoan::edit refuses
 * otherwise). The lender and where the money came from stay: delete and record again.
 */
final class LoanUpdateRequest extends MoneyFormRequest
{
    public function authorize(): bool
    {
        $loan = $this->route('loan');

        return $loan instanceof Loan && ($this->user()?->can('update', $loan) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_paise' => $this->amountRules(),
            'date' => $this->dateRules(),
            'purpose' => ['nullable', 'string', 'max:200'],
            'instalment_paise' => ['nullable', 'integer', 'min:1', 'max:'.Money::maxPaise(), 'lte:amount_paise'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->sharedMessages() + [
            'instalment_paise.min' => 'Enter an instalment above ₹0, or leave it empty.',
            'instalment_paise.lte' => 'The instalment can’t be more than the loan.',
            'due_on.after_or_equal' => 'The due date can’t be before the loan.',
            'due_on.date_format' => 'Pick a date.',
        ];
    }

    public function loanEdit(): LoanEdit
    {
        return new LoanEdit(
            amountPaise: $this->integer('amount_paise'),
            lentOn: $this->dateValue(),
            purpose: $this->optionalText('purpose'),
            instalmentPaise: $this->filled('instalment_paise') ? $this->integer('instalment_paise') : null,
            dueOn: $this->filled('due_on') ? (CarbonImmutable::createFromFormat('!Y-m-d', $this->string('due_on')->toString()) ?: null) : null,
        );
    }
}
