<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\PayerInput;
use App\Enums\CategoryKind;
use App\Enums\PayerMode;
use App\Enums\PayerType;
use App\Enums\ProjectStatus;
use App\Models\Expense;
use App\Models\Member;
use App\Policies\ExpensePolicy;
use App\Services\Finance\Attachments;
use App\Support\CurrentCompany;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add or edit an expense (Expenses.dc.html modal, MobileExpenses.dc.html sheet, spec §10).
 *
 * Paid by: Full is one payer for the whole amount, Half splits it 50/50 between two
 * (odd paisa to the first), Custom takes any number of lines that must add up to the
 * amount, and Company is the company account alone. Each line is a partner, a team
 * member or the company, paid from a money account or (people only) a personal card.
 * A user with the Member role can only name themselves or the company as payers and
 * can't create categories.
 */
final class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->expense();

        return $expense === null
            ? ($this->user()?->can('create', Expense::class) ?? false)
            : ($this->user()?->can('update', $expense) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = app(CurrentCompany::class)->require();
        $expense = $this->expense();
        $member = app(ExpensePolicy::class)->ownOnly();
        $keep = fn (string $column, ?array $current) => fn ($q) => $q->whereNull($column)->when($current, fn ($w) => $w->orWhereIn('id', $current));
        $payerAccounts = $expense?->payers->pluck('money_account_id')->filter()->values()->all();
        $payerPartners = $expense?->payers->pluck('partner_id')->filter()->values()->all();

        return [
            'description' => ['required', 'string', 'max:200'],
            'amount_paise' => ['required', 'integer', 'min:1', 'max:'.Money::MAX_PAISE],
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today()->addDays(31)->toDateString()],
            'category_id' => ['nullable', 'required_without:new_category', 'integer',
                Rule::exists('categories', 'id')->where('company_id', $company->id)->where('kind', CategoryKind::Expense->value)
                    ->where($keep('archived_at', $expense ? [$expense->category_id] : null))],
            'new_category' => [$member ? 'prohibited' : 'nullable', 'string', 'max:60'],
            'project_id' => ['nullable', 'required_if_accepted:billable', 'integer', Rule::exists('projects', 'id')->where('company_id', $company->id)
                ->where(fn ($q) => $q->where('status', ProjectStatus::Active->value)->when($expense?->project_id, fn ($w, $id) => $w->orWhere('id', $id)))],
            'billable' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receipt' => Attachments::RULES,
            'remove_receipt' => ['boolean'],
            'payer_mode' => ['required', Rule::enum(PayerMode::class)],
            'payers' => ['required', 'array', 'min:1', 'max:12'],
            'payers.*.type' => ['required', Rule::enum(PayerType::class)],
            'payers.*.partner_id' => ['nullable', 'required_if:payers.*.type,partner', 'integer',
                Rule::exists('partners', 'id')->where('company_id', $company->id)->where($keep('archived_at', $payerPartners))],
            'payers.*.member_id' => ['nullable', 'required_if:payers.*.type,member', 'integer',
                Rule::exists('members', 'id')->where('company_id', $company->id)->whereNull('deleted_at')],
            'payers.*.account_id' => ['nullable', 'required_if:payers.*.type,company', 'integer',
                Rule::exists('money_accounts', 'id')->where('company_id', $company->id)->where($keep('archived_at', $payerAccounts))],
            'payers.*.amount_paise' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.required' => 'Add a description.',
            'amount_paise.required' => 'Enter an amount.',
            'amount_paise.min' => 'Enter an amount.',
            'spent_on.required' => 'Pick the date.',
            'spent_on.before_or_equal' => 'That date is too far ahead.',
            'category_id.required_without' => 'Pick a category.',
            'category_id.exists' => 'Pick an active expense category.',
            'new_category.prohibited' => 'Ask an Admin or partner to add categories.',
            'project_id.required_if_accepted' => 'Pick the client’s project to bill.',
            'project_id.exists' => 'Pick an active project.',
            'receipt.mimes' => 'Attach a PDF, JPG or PNG.',
            'receipt.max' => 'The file is over 10 MB.',
            'payers.required' => 'Say who paid.',
            'payers.*.partner_id.required_if' => 'Pick the partner who paid.',
            'payers.*.partner_id.exists' => 'Pick an active partner.',
            'payers.*.member_id.required_if' => 'Pick the team member who paid.',
            'payers.*.member_id.exists' => 'Pick a team member.',
            'payers.*.account_id.required_if' => 'The company pays from one of its accounts.',
            'payers.*.account_id.exists' => 'Pick an active account.',
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mode = PayerMode::from($this->string('payer_mode')->toString());
            $payers = $this->payerInputs();
            $count = count($payers);
            $amount = $this->integer('amount_paise');

            $shapeError = match ($mode) {
                PayerMode::Full => $count !== 1 || $payers[0]->type === PayerType::Company ? 'Full means one person paid it all.' : null,
                PayerMode::Half => $count !== 2 || $payers[0]->type === PayerType::Company || $payers[1]->type === PayerType::Company ? 'Half splits it between two people.' : null,
                PayerMode::Company => $count !== 1 || $payers[0]->type !== PayerType::Company ? 'Company means the company account paid it all.' : null,
                PayerMode::Custom => null,
            };

            if ($shapeError !== null) {
                $validator->errors()->add('payers', $shapeError);

                return;
            }

            foreach ($payers as $payer) {
                if ($payer->type === PayerType::Company && $payer->accountId === null) {
                    $validator->errors()->add('payers', 'The company pays from one of its accounts.');

                    return;
                }
            }

            // Full, Half and Company amounts follow from the total; Custom lines must add up.
            $sum = array_sum(array_map(fn (PayerInput $p) => $p->amountPaise, $payers));

            if ($mode === PayerMode::Custom && $sum !== $amount) {
                $gap = Money::format(abs($sum - $amount));
                $validator->errors()->add('payers', $sum > $amount
                    ? "Payers add up to {$gap} more than the amount."
                    : "Payers add up to {$gap} less than the amount.");

                return;
            }

            if ($mode === PayerMode::Custom && collect($payers)->contains(fn (PayerInput $p) => $p->amountPaise === 0)) {
                $validator->errors()->add('payers', 'Every payer needs an amount.');

                return;
            }

            if (app(ExpensePolicy::class)->ownOnly()) {
                $mine = Member::query()->where('user_id', $this->user()?->id)->pluck('id');

                foreach ($payers as $payer) {
                    if ($payer->type === PayerType::Partner || ($payer->type === PayerType::Member && ! $mine->contains($payer->memberId))) {
                        $validator->errors()->add('payers', 'You can only add expenses you or the company paid.');

                        return;
                    }
                }
            }
        }];
    }

    public function expenseData(): ExpenseData
    {
        $file = $this->file('receipt');

        return new ExpenseData(
            description: trim($this->string('description')->toString()),
            amountPaise: $this->integer('amount_paise'),
            spentOn: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('spent_on')->toString()) ?: CarbonImmutable::today(),
            categoryId: $this->filled('new_category') ? null : $this->integer('category_id'),
            newCategory: $this->filled('new_category') ? trim($this->string('new_category')->toString()) : null,
            projectId: $this->filled('project_id') ? $this->integer('project_id') : null,
            billable: $this->boolean('billable'),
            notes: $this->filled('notes') ? trim($this->string('notes')->toString()) : null,
            mode: PayerMode::from($this->string('payer_mode')->toString()),
            payers: $this->splitAmounts($this->payerInputs()),
            receipt: $file instanceof UploadedFile ? $file : null,
            removeReceipt: $this->boolean('remove_receipt'),
        );
    }

    /** @return list<PayerInput> */
    private function payerInputs(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values((array) $this->input('payers', []));

        return array_map(function (array $row): PayerInput {
            $type = PayerType::from((string) $row['type']);
            $int = fn (string $key): ?int => isset($row[$key]) && is_numeric($row[$key]) ? (int) $row[$key] : null;

            return new PayerInput(
                type: $type,
                partnerId: $type === PayerType::Partner ? $int('partner_id') : null,
                memberId: $type === PayerType::Member ? $int('member_id') : null,
                accountId: $int('account_id'),
                amountPaise: (int) ($int('amount_paise') ?? 0),
            );
        }, $rows);
    }

    /**
     * Full and Company carry the whole amount; Half gives the odd paisa to the first payer.
     *
     * @param  list<PayerInput>  $payers
     * @return list<PayerInput>
     */
    private function splitAmounts(array $payers): array
    {
        $amount = $this->integer('amount_paise');
        $mode = PayerMode::from($this->string('payer_mode')->toString());
        $with = fn (PayerInput $p, int $paise) => new PayerInput($p->type, $p->partnerId, $p->memberId, $p->accountId, $paise);

        return match ($mode) {
            PayerMode::Full, PayerMode::Company => [$with($payers[0], $amount)],
            PayerMode::Half => [$with($payers[0], $amount - intdiv($amount, 2)), $with($payers[1], intdiv($amount, 2))],
            PayerMode::Custom => $payers,
        };
    }

    private function expense(): ?Expense
    {
        $expense = $this->route('expense');

        return $expense instanceof Expense ? $expense : null;
    }
}
