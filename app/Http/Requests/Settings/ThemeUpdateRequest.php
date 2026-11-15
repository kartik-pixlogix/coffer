<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\Theme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ThemeUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'theme' => ['required', Rule::enum(Theme::class)],
        ];
    }

    public function theme(): Theme
    {
        return Theme::from($this->string('theme')->toString());
    }
}
