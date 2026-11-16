<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use App\Actions\Income\IncomeData;
use App\Enums\AttributionMode;
use App\Enums\CategoryKind;
use App\Enums\ProjectStatus;
use App\Models\Income;
use App\Services\Finance\Attachments;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Add or edit income (Income.dc.html modal, spec §9). "Received into" is one value:
 * `account:{id}` for a money account or `partner:{id}` for a partner personally.
 * "Brought in by" is optional only when the company shares profit JV-pooled (spec §4.4).
 * Archived categories, accounts, partners and projects can stay on the record being
 * edited but can't be picked anew.
 */
final class IncomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('action.income.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = app(CurrentCompany::class)->require();
        $income = $this->income();
        $pooled = $company->attribution_mode === AttributionMode::JvPooled;

        $keepOr = fn (string $table, ?int $current, Closure $active) => Rule::exists($table, 'id')
            ->where('company_id', $company->id)
            ->where(fn ($q) => $active($q)->when($current !== null, fn ($w) => $w->orWhere('id', $current)));

        return [
            'source' => ['required', 'string', 'max:200'],
            'amount_paise' => ['required', 'integer', 'min:1', 'max:'.(10 ** 13)],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today()->addYear()->toDateString()],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('company_id', $company->id)->where('kind', CategoryKind::Income->value)
                ->where(fn ($q) => $q->whereNull('archived_at')->when($income !== null, fn ($w) => $w->orWhere('id', $income?->category_id)))],
            'brought_in_by' => [$pooled ? 'nullable' : 'required', 'integer',
                $keepOr('partners', $income?->brought_in_by, fn ($q) => $q->whereNull('archived_at'))],
            'received_into' => ['required', 'string', 'regex:/^(account|partner):\d+$/', function (string $attribute, mixed $value, Closure $fail) use ($company, $income): void {
                [$kind, $id] = explode(':', (string) $value) + [1 => '0'];
                $table = $kind === 'account' ? 'money_accounts' : 'partners';
                $current = $kind === 'account' ? $income?->money_account_id : $income?->received_by;
                $ok = DB::table($table)->where('company_id', $company->id)->where('id', (int) $id)
                    ->where(fn ($q) => $q->whereNull('archived_at')->when($current !== null, fn ($w) => $w->orWhere('id', $current)))
                    ->exists();

                if (! $ok) {
                    $fail('Pick where the money went.');
                }
            }],
            'project_id' => ['nullable', 'integer', $keepOr('projects', $income?->project_id, fn ($q) => $q->where('status', ProjectStatus::Active->value))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment' => Attachments::RULES,
            'remove_attachment' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source.required' => 'Add who paid and what it’s for.',
            'amount_paise.required' => 'Enter an amount.',
            'amount_paise.min' => 'Enter an amount.',
            'received_on.required' => 'Pick the date it came in.',
            'received_on.before_or_equal' => 'That date is too far ahead.',
            'category_id.required' => 'Pick a category.',
            'category_id.exists' => 'Pick an active income category.',
            'brought_in_by.required' => 'Pick the partner who brought it in.',
            'brought_in_by.exists' => 'Pick an active partner.',
            'received_into.required' => 'Pick where the money went.',
            'received_into.regex' => 'Pick where the money went.',
            'project_id.exists' => 'Pick an active project.',
            'attachment.mimes' => 'Attach a PDF, JPG or PNG.',
            'attachment.max' => 'The file is over 10 MB.',
        ];
    }

    public function incomeData(): IncomeData
    {
        [$kind, $id] = explode(':', $this->string('received_into')->toString());
        $file = $this->file('attachment');

        return new IncomeData(
            source: trim($this->string('source')->toString()),
            amountPaise: $this->integer('amount_paise'),
            receivedOn: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('received_on')->toString()) ?: CarbonImmutable::today(),
            categoryId: $this->integer('category_id'),
            broughtInBy: $this->filled('brought_in_by') ? $this->integer('brought_in_by') : null,
            moneyAccountId: $kind === 'account' ? (int) $id : null,
            receivedBy: $kind === 'partner' ? (int) $id : null,
            projectId: $this->filled('project_id') ? $this->integer('project_id') : null,
            notes: $this->filled('notes') ? trim($this->string('notes')->toString()) : null,
            attachment: $file instanceof UploadedFile ? $file : null,
            removeAttachment: $this->boolean('remove_attachment'),
        );
    }

    private function income(): ?Income
    {
        $income = $this->route('income');

        return $income instanceof Income ? $income : null;
    }
}
