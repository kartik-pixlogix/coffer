<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\AttributionMode;
use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings › Company (spec §3.2, D63). The Settings page permission is checked by the
 * route; this validates the fields: GSTIN format, a short code unique in the workspace,
 * at least one working day and a sensible standard day.
 */
final class CompanySettingsRequest extends FormRequest
{
    /** 15 characters: state code, PAN, entity number, Z, check character. */
    public const string GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const array CURRENCIES = ['INR', 'USD'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'short_code' => Str::upper(trim((string) $this->input('short_code'))),
            'gstin' => $this->filled('gstin') ? Str::upper(str_replace(' ', '', (string) $this->input('gstin'))) : null,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'gstin' => ['nullable', 'string', 'regex:'.self::GSTIN_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'short_code' => ['required', 'string', 'regex:/^[A-Z]{2,6}$/', $this->uniqueCode()],
            'opening_balance' => ['required', function (string $attribute, mixed $value, Closure $fail): void {
                if (Money::parseRupees(is_scalar($value) ? (string) $value : null) === null) {
                    $fail('Enter the opening balance in rupees, like 2,50,000.');
                }
            }],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'attribution_mode' => ['required', Rule::enum(AttributionMode::class)],
            'fy_start_month' => ['required', 'integer', 'between:1,12'],
            'working_days' => ['required', 'integer', 'between:1,127'],
            'hours_per_day' => ['required', 'numeric', 'min:1', 'max:24'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Add the company’s name.',
            'gstin.regex' => 'A GSTIN is 15 characters, like 27ABCDE1234F1Z5.',
            'short_code.regex' => 'Use 2–6 letters, like SPJV.',
            'working_days.between' => 'Pick at least one working day.',
            'hours_per_day.min' => 'A working day is at least 1 hour.',
            'hours_per_day.max' => 'A working day is at most 24 hours.',
        ];
    }

    /**
     * @return array{name: string, legal_name: string|null, gstin: string|null, address: string|null, short_code: string, opening_balance_paise: int, currency: string, attribution_mode: string, fy_start_month: int, working_days: int, hours_per_day_centi: int}
     */
    public function settings(): array
    {
        $blank = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => trim((string) $this->input('name')),
            'legal_name' => $blank($this->input('legal_name')),
            'gstin' => $blank($this->input('gstin')),
            'address' => $blank($this->input('address')),
            'short_code' => (string) $this->input('short_code'),
            'opening_balance_paise' => (int) Money::parseRupees((string) $this->input('opening_balance')),
            'currency' => (string) $this->input('currency'),
            'attribution_mode' => (string) $this->input('attribution_mode'),
            'fy_start_month' => (int) $this->input('fy_start_month'),
            'working_days' => (int) $this->input('working_days'),
            'hours_per_day_centi' => (int) round(((float) $this->input('hours_per_day')) * 100),
        ];
    }

    private function uniqueCode(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $company = app(CurrentCompany::class)->require();

            if (Company::query()->where('workspace_id', $company->workspace_id)->where('short_code', $value)->whereKeyNot($company->id)->exists()) {
                $fail("Another company in this workspace uses {$value}. Pick another code.");
            }
        };
    }
}
