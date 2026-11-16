<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Pay out a personal-card balance (Expenses, D50): `payee` is `partner:{id}` or
 * `member:{id}`, plus amount (at most what is owed, checked when posting), date, the
 * money account it's paid from and a note. Needs "Manage capital and loans".
 */
final class ReimbursementRequest extends MoneyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('action.capital.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payee' => ['required', 'string', 'regex:/^(partner|member):\d+$/', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->payeeModel() === null) {
                    $fail('Pick who to pay.');
                }
            }],
            'amount_paise' => $this->amountRules(),
            'date' => $this->dateRules(),
            'money_account_id' => ['required', 'integer', $this->activeIn('money_accounts')],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->sharedMessages() + ['payee.required' => 'Pick who to pay.', 'payee.regex' => 'Pick who to pay.'];
    }

    /** @return array{payee: Partner|Member, amount: int, date: CarbonImmutable, account: MoneyAccount, note: string|null} */
    public function payout(): array
    {
        $payee = $this->payeeModel();
        abort_if($payee === null, 422);

        return [
            'payee' => $payee,
            'amount' => $this->integer('amount_paise'),
            'date' => $this->dateValue(),
            'account' => $this->moneyAccount(),
            'note' => $this->optionalText('note'),
        ];
    }

    /** The partner or member named by `payee`, in the current company. */
    private function payeeModel(): Partner|Member|null
    {
        [$kind, $id] = explode(':', $this->string('payee')->toString()) + [1 => '0'];

        return match ($kind) {
            'partner' => Partner::query()->find((int) $id),
            'member' => Member::query()->find((int) $id),
            default => null,
        };
    }
}
