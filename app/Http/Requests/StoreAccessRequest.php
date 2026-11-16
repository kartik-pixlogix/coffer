<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Permissions\Catalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['required', 'string', Rule::in(array_keys(Catalog::PAGES))],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
