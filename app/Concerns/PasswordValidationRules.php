<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords: at least 12 characters (spec §17).
     * Sign-up has a single password field (SignUp.dc.html); reset and change ask twice.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(bool $confirmed = true): array
    {
        $rules = ['required', 'string', 'max:255', Password::default()];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
