<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Support\Accent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccentUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preset' => ['required', 'string', Rule::in([...array_keys(Accent::presets()), Accent::CUSTOM])],
            'stops' => ['exclude_unless:preset,'.Accent::CUSTOM, 'required', 'array', 'size:3'],
            'stops.*' => ['exclude_unless:preset,'.Accent::CUSTOM, 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'angle' => ['exclude_unless:preset,'.Accent::CUSTOM, 'required', 'integer', 'between:0,360'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'stops.*.regex' => 'Pick each colour with the colour picker, or type it as #rrggbb.',
        ];
    }

    public function accent(): Accent
    {
        $preset = $this->string('preset')->toString();

        if ($preset !== Accent::CUSTOM) {
            return Accent::make($preset);
        }

        /** @var list<string> $stops */
        $stops = array_values((array) $this->input('stops'));

        return Accent::make(Accent::CUSTOM, $stops, $this->integer('angle'));
    }
}
