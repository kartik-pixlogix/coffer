<?php

namespace App\Actions\Fortify;

use App\Actions\Workspaces\CreateWorkspace;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Sign-up (spec §18): name, company name, work email, password and the terms. Creates
 * the user and their workspace; email verification and the wizard follow.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly CreateWorkspace $createWorkspace) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'company_name' => ['required', 'string', 'max:120'],
            'password' => $this->passwordRules(confirmed: false),
            'terms' => ['accepted'],
        ], [
            'name.required' => 'Add your name.',
            'company_name.required' => 'Add your company’s name.',
            'email.email' => 'Use your work email so teammates can find the company.',
            'email.unique' => 'There’s already an account with this email. Sign in instead.',
            'password.min' => 'Use at least 12 characters. A short sentence works well.',
            'terms.accepted' => 'Agree to the Terms and Privacy Policy to continue.',
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->createWorkspace->handle($user, $input['company_name']);

            return $user;
        });
    }
}
