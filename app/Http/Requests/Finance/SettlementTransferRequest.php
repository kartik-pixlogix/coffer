<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Ledger\Period;
use App\Ledger\SettlementCalculator;
use App\Models\SettlementTransfer;
use App\Models\Side;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Record transfer (Settlements.dc.html panel, spec §13): from side → to side (they must
 * differ), amount, date and a bank reference. Needs "Record settlement transfer".
 * An amount above what is owed is allowed (the panel warns).
 */
final class SettlementTransferRequest extends MoneyFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('action.settlements.record') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $side = Rule::exists('sides', 'id')->where('company_id', $this->company()->id);

        return [
            'from_side_id' => ['required', 'integer', $side],
            'to_side_id' => ['required', 'integer', 'different:from_side_id', $side],
            'amount_paise' => $this->amountRules(),
            'date' => $this->dateRules(),
            'reference' => ['nullable', 'string', 'max:100'],
            'confirm_over' => ['boolean'],
        ];
    }

    /**
     * F7 (D116): more than the "from" side owes the "to" side (in the transfer's fiscal
     * year; when editing, before this transfer) needs `confirm_over` from the drawer's
     * "Record anyway" tick. D54 warned; the server now insists.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->boolean('confirm_over')) {
                return;
            }

            $company = $this->company();
            $from = $this->integer('from_side_id');
            $to = $this->integer('to_side_id');
            $result = app(SettlementCalculator::class)->calculate($company, Period::fiscalYearOf($company, $this->dateValue()));
            $owed = 0;

            foreach ($result->transfers as $suggested) {
                if ($suggested['from']->id === $from && $suggested['to']->id === $to) {
                    $owed += $suggested['amount'];
                }
            }

            $editing = $this->route('transfer');

            if ($editing instanceof SettlementTransfer && $editing->from_side_id === $from && $editing->to_side_id === $to) {
                $owed += $editing->amount_paise;
            }

            if ($this->integer('amount_paise') > $owed) {
                $validator->errors()->add('confirm_over', 'That is more than is owed ('.Money::format($owed).'). Tick “Record anyway” to record it as it is.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->sharedMessages() + [
            'from_side_id.required' => 'Pick the side that paid.',
            'from_side_id.exists' => 'Pick a side of this joint venture.',
            'to_side_id.required' => 'Pick the side that was paid.',
            'to_side_id.exists' => 'Pick a side of this joint venture.',
            'to_side_id.different' => 'From and to must be different sides.',
        ];
    }

    /** @return array{from: Side, to: Side, amount: int, date: CarbonImmutable, reference: string|null} */
    public function transfer(): array
    {
        return [
            'from' => Side::query()->findOrFail($this->integer('from_side_id')),
            'to' => Side::query()->findOrFail($this->integer('to_side_id')),
            'amount' => $this->integer('amount_paise'),
            'date' => $this->dateValue(),
            'reference' => $this->optionalText('reference'),
        ];
    }
}
