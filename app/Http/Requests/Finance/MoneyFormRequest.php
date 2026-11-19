<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Support\BooksWindow;
use App\Support\CurrentCompany;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared rules for the capital, loan, reimbursement and settlement forms (spec §10–§13):
 * a positive amount in paise, a date (`Y-m-d`, at most a year ahead), and active
 * partners and money accounts of the current company.
 */
abstract class MoneyFormRequest extends FormRequest
{
    protected function company(): Company
    {
        return app(CurrentCompany::class)->require();
    }

    /** @return list<string> */
    protected function amountRules(): array
    {
        return ['required', 'integer', 'min:1', 'max:'.Money::maxPaise()];
    }

    /** @return list<string> */
    protected function dateRules(): array
    {
        return ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today()->addYear()->toDateString(), BooksWindow::rule($this->company())];
    }

    protected function activeIn(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('company_id', $this->company()->id)->whereNull('archived_at');
    }

    /**
     * G12 (D116): friendly names in any framework message.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount_paise' => 'amount', 'money_account_id' => 'money account', 'partner_id' => 'partner',
            'from_side_id' => 'from side', 'to_side_id' => 'to side', 'instalment_paise' => 'instalment',
            'confirm_over' => 'record anyway', 'confirm_overdraw' => 'record anyway',
        ];
    }

    /** @return array<string, string> */
    protected function sharedMessages(): array
    {
        return [
            'amount_paise.required' => 'Enter an amount above ₹0.',
            'amount_paise.min' => 'Enter an amount above ₹0.',
            'amount_paise.max' => Money::maxMessage(),
            'date.required' => 'Pick a date.',
            'date.date_format' => 'Pick a date.',
            'date.before_or_equal' => 'That date is too far ahead.',
            'date.after_or_equal' => BooksWindow::message($this->company()),
            'partner_id.required' => 'Pick a partner.',
            'partner_id.exists' => 'Pick an active partner.',
            'money_account_id.required' => 'Pick a money account.',
            'money_account_id.exists' => 'Pick an active money account.',
        ];
    }

    protected function dateValue(string $key = 'date'): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string($key)->toString()) ?: CarbonImmutable::today();
    }

    protected function partner(): Partner
    {
        /** @var Partner */
        return Partner::query()->findOrFail($this->integer('partner_id'));
    }

    protected function moneyAccount(): MoneyAccount
    {
        /** @var MoneyAccount */
        return MoneyAccount::query()->findOrFail($this->integer('money_account_id'));
    }

    protected function optionalText(string $key): ?string
    {
        $value = trim($this->string($key)->toString());

        return $value === '' ? null : $value;
    }
}
