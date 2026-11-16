<?php

declare(strict_types=1);

namespace App\Http\Requests\Timesheets;

use App\Enums\MemberStatus;
use App\Enums\ProjectStatus;
use App\Models\Member;
use App\Services\Timesheets\Hours;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One timesheet entry or a month's total (spec §8.1): member, a day or a month, hours as
 * "2.5" or "2:30", project, partner (defaults to the member's), billable and notes.
 * Authorisation is the controller's (TimeEntryPolicy needs the member).
 */
final class TimeEntryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $company = app(CurrentCompany::class)->require();
        $updating = $this->route('entry') !== null;

        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->where('company_id', $company->id)->whereNull('deleted_at')
                ->when(! $updating, fn ($rule) => $rule->where('status', MemberStatus::Active->value))],
            'mode' => $updating ? ['prohibited'] : ['required', Rule::in(['day', 'month'])],
            'date' => ['required_unless:mode,month', 'nullable', 'date_format:Y-m-d'],
            'month' => ['required_if:mode,month', 'nullable', 'date_format:Y-m'],
            'saturdays' => ['boolean'],
            'hours' => ['required', 'string', 'max:12', function (string $attribute, mixed $value, Closure $fail): void {
                $centi = Hours::parse((string) $value);

                if ($centi === null) {
                    $fail('Enter hours like 2.5 or 2:30.');
                } elseif ($centi < Hours::MIN) {
                    $fail('Hours can’t be less than 0.25.');
                } elseif ($this->input('mode') !== 'month' && $centi > Hours::MAX) {
                    $fail('Hours can’t be more than 24 in a day.');
                }
            }],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where('company_id', $company->id)
                ->when(! $updating, fn ($rule) => $rule->where('status', ProjectStatus::Active->value))],
            'partner_id' => ['nullable', 'integer', Rule::exists('partners', 'id')->where('company_id', $company->id)],
            'billable' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'member_id.required' => 'Pick who did the work.',
            'member_id.exists' => 'Pick an active team member.',
            'date.required_unless' => 'Pick the day.',
            'month.required_if' => 'Pick the month.',
            'hours.required' => 'Enter the hours.',
            'project_id.required' => 'Pick a project.',
            'project_id.exists' => 'Pick an active project.',
        ];
    }

    public function member(): Member
    {
        /** @var Member */
        return Member::query()->findOrFail($this->integer('member_id'));
    }

    public function centi(): int
    {
        return (int) Hours::parse($this->string('hours')->toString());
    }

    public function day(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('date')->toString()) ?: CarbonImmutable::today();
    }

    public function monthStart(): CarbonImmutable
    {
        return (CarbonImmutable::createFromFormat('!Y-m', $this->string('month')->toString()) ?: CarbonImmutable::today())->startOfMonth();
    }

    /**
     * @return array{project_id: int, partner_id: int|null, billable: bool, notes: string|null}
     */
    public function details(): array
    {
        return [
            'project_id' => $this->integer('project_id'),
            'partner_id' => $this->filled('partner_id') ? $this->integer('partner_id') : null,
            'billable' => $this->boolean('billable', true),
            'notes' => $this->filled('notes') ? trim($this->string('notes')->toString()) : null,
        ];
    }
}
