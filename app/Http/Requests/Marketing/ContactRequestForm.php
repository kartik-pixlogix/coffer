<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Enums\ContactTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The Contact / Book a demo form (spec §21, D84). `website` is the honeypot. */
final class ContactRequestForm extends FormRequest
{
    /** Team sizes the form offers. */
    public const array TEAM_SIZES = ['1-5', '6-20', '21-50', '51-200', '200+'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'topic' => ['required', Rule::enum(ContactTopic::class)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'company' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-\s.]*$/'],
            'team_size' => ['nullable', Rule::in(self::TEAM_SIZES)],
            'message' => [Rule::requiredIf(fn () => $this->input('topic') !== ContactTopic::Demo->value), 'nullable', 'string', 'max:5000'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Add your name.',
            'email.required' => 'Add your work email so we can reply.',
            'email.email' => 'That email doesn’t look right.',
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
            'message.required' => 'Tell us a little about what you need.',
        ];
    }

    /** A bot filled the hidden field. */
    public function isSpam(): bool
    {
        return filled($this->input('website'));
    }
}
