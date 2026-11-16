<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot the app and run against a fresh database.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/**
 * A verified user with `$role` in `$company` (a new Single company by default).
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(CompanyRole $role = CompanyRole::Admin, ?Company $company = null, array $attributes = []): User
{
    $company ??= Company::factory()->create();
    $user = User::factory()->create($attributes);

    app(SetCompanyRole::class)->handle($company, $user, $role);

    return $user->refresh();
}

/** The first (and usually only) company a test user belongs to. */
function companyOf(User $user): Company
{
    /** @var Company */
    return $user->companies()->firstOrFail();
}
