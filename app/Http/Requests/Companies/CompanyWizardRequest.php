<?php

declare(strict_types=1);

namespace App\Http\Requests\Companies;

use App\Enums\CompanyRole;
use App\Enums\CompanyType;
use App\Enums\CostType;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Equity;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The create-company wizard's data (spec §3.1), validated per step on "Next" and in
 * full on Launch. Messages say how to fix the problem, as on the designs.
 *
 * Payload: company{type,name,short_code,opening_balance,currency}, sides[{key,name,equity}],
 * partners[{key,name,email,role,colour,equity,side_key}], members[{name,designation,email,
 * cost_type,rate,partner_key}], invite_members.
 */
class CompanyWizardRequest extends FormRequest
{
    public const array CURRENCIES = ['INR', 'USD', 'AED'];

    /**
     * Steps to check: 1 = company & partners, 2 = team; Launch checks both.
     *
     * @return list<int>
     */
    protected function steps(): array
    {
        $step = (int) $this->input('step', 0);

        return $step === 1 ? [1] : ($step === 2 ? [2] : [1, 2]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $company = (array) $this->input('company', []);

        if (isset($company['short_code']) && is_string($company['short_code'])) {
            $company['short_code'] = Str::upper(trim($company['short_code']));
            $this->merge(['company' => $company]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        $steps = $this->steps();

        if (in_array(1, $steps, true)) {
            $rules += [
                'company' => ['required', 'array'],
                'company.type' => ['required', Rule::enum(CompanyType::class)],
                'company.name' => ['required', 'string', 'max:60'],
                'company.short_code' => ['required', 'string', 'regex:/^[A-Z]{2,6}$/', $this->uniqueCode()],
                'company.opening_balance' => ['nullable', $this->rupees()],
                'company.currency' => ['required', Rule::in(self::CURRENCIES)],
                'sides' => [Rule::requiredIf($this->isJv()), 'array', $this->isJv() ? 'min:2' : 'max:0'],
                'sides.*.key' => ['required', 'string', 'max:40', 'distinct'],
                'sides.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
                'sides.*.equity' => ['required', $this->percent()],
                'partners' => ['required', 'array', 'min:1', 'max:30'],
                'partners.*.key' => ['required', 'string', 'max:40', 'distinct'],
                'partners.*.name' => ['required', 'string', 'max:120'],
                'partners.*.email' => ['nullable', 'string', 'email', 'max:255', 'distinct:ignore_case'],
                'partners.*.role' => ['required', Rule::in(array_map(fn (CompanyRole $r) => $r->value, CompanyRole::partnerRoles()))],
                'partners.*.colour' => ['required', 'integer', 'between:1,6'],
                'partners.*.equity' => ['required', $this->percent()],
                'partners.*.side_key' => [Rule::requiredIf($this->isJv()), 'nullable', 'string'],
            ];
        }

        if (in_array(2, $steps, true)) {
            $rules += [
                'members' => ['present', 'array', 'max:100'],
                'members.*.name' => ['required', 'string', 'max:120'],
                'members.*.designation' => ['nullable', 'string', 'max:120'],
                'members.*.email' => ['nullable', 'string', 'email', 'max:255', 'distinct:ignore_case'],
                'members.*.cost_type' => ['required', Rule::enum(CostType::class)],
                'members.*.rate' => ['required', $this->rupees()],
                'members.*.partner_key' => ['required', 'string'],
                'invite_members' => ['boolean'],
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'company.name.required' => 'Add a company name.',
            'company.name.max' => 'Keep the company name to 60 characters.',
            'company.short_code.required' => 'Add a short code of 2–6 letters.',
            'company.short_code.regex' => 'Use 2–6 letters, like VL or SPJV.',
            'sides.min' => 'A joint venture needs at least two sides.',
            'sides.*.name.required' => 'Name every side.',
            'sides.*.name.distinct' => 'Give each side its own name.',
            'partners.*.name.required' => 'Name every partner.',
            'partners.*.email.email' => 'Check the email address.',
            'partners.*.email.distinct' => 'Each partner needs their own email.',
            'members.*.name.required' => 'Name every team member.',
            'members.*.email.email' => 'Check the email address.',
            'members.*.email.distinct' => 'Each team member needs their own email.',
        ];
    }

    /**
     * The 100% rules (spec §3.1): partners in a Single company; sides and each side in a JV.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $steps = $this->steps();

            if (in_array(1, $steps, true)) {
                $this->checkEquity($validator);
            }

            if (in_array(2, $steps, true)) {
                $this->checkMembers($validator);
            }
        }];
    }

    public function isJv(): bool
    {
        return $this->input('company.type') === CompanyType::Jv->value;
    }

    /** The workspace the new company goes into: the one the user owns. */
    public function workspace(): ?Workspace
    {
        /** @var User $user */
        $user = $this->user();

        return $user->ownedWorkspaces()->oldest('id')->first();
    }

    private function checkEquity(Validator $validator): void
    {
        /** @var list<array<string, mixed>> $partners */
        $partners = array_values((array) $this->input('partners', []));

        if (! $this->isJv()) {
            $total = array_sum(array_map(fn ($p) => (int) Equity::parse($p['equity'] ?? null), $partners));

            if ($total !== Equity::WHOLE) {
                $validator->errors()->add('partners', Equity::gapMessage($total));
            }

            return;
        }

        /** @var list<array<string, mixed>> $sides */
        $sides = array_values((array) $this->input('sides', []));
        $sidesTotal = array_sum(array_map(fn ($s) => (int) Equity::parse($s['equity'] ?? null), $sides));

        if ($sidesTotal !== Equity::WHOLE) {
            $validator->errors()->add('sides', Equity::gapMessage($sidesTotal, 'Sides', 'they total 100%'));
        }

        $keys = array_column($sides, 'key');

        foreach ($partners as $index => $partner) {
            if (! in_array($partner['side_key'] ?? null, $keys, true)) {
                $validator->errors()->add("partners.{$index}.side_key", 'Choose a side for this partner.');
            }
        }

        foreach ($sides as $index => $side) {
            $within = array_filter($partners, fn ($p) => ($p['side_key'] ?? null) === $side['key']);

            if ($within === []) {
                $validator->errors()->add("sides.{$index}", "Add a partner to {$side['name']}.");

                continue;
            }

            $total = array_sum(array_map(fn ($p) => (int) Equity::parse($p['equity'] ?? null), $within));

            if ($total !== Equity::WHOLE) {
                $validator->errors()->add("sides.{$index}", Equity::gapMessage($total, "{$side['name']} partners", 'this side totals 100%'));
            }
        }
    }

    private function checkMembers(Validator $validator): void
    {
        $partnerKeys = array_column((array) $this->input('partners', []), 'key');

        foreach (array_values((array) $this->input('members', [])) as $index => $member) {
            if (! in_array($member['partner_key'] ?? null, $partnerKeys, true)) {
                $validator->errors()->add("members.{$index}.partner_key", 'Choose the partner this member bills to.');
            }
        }
    }

    private function uniqueCode(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $workspace = $this->workspace();

            if ($workspace !== null && Company::query()->withTrashed()->where('workspace_id', $workspace->id)->where('short_code', $value)->exists()) {
                $fail("Another company in {$workspace->name} uses {$value}. Pick another code.");
            }
        };
    }

    private function percent(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_scalar($value) || Equity::parse((string) $value) === null) {
                $fail('Use a percentage from 0 to 100, with up to 2 decimals.');
            }
        };
    }

    private function rupees(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_scalar($value) || Money::parseRupees((string) $value) === null) {
                $fail('Use an amount in rupees, like 2,50,000.');
            }
        };
    }
}
