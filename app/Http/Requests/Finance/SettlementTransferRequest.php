<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Models\Side;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

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
        ];
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
