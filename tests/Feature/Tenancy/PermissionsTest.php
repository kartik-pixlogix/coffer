<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

dataset('roles', fn () => CompanyRole::cases());

test('each role gets exactly the spec §2 default pages and actions', function (CompanyRole $role) {
    $user = userWithRole($role);
    $company = companyOf($user);

    app(CurrentCompany::class)->set($company, $role);

    foreach (Catalog::PAGES as $page => $definition) {
        $expected = $role === CompanyRole::Admin || $definition['defaults'][$role->value];

        expect($user->can(Catalog::pagePermission($page)))
            ->toBe($expected, "{$role->value} → page {$page}");
    }

    foreach (Catalog::ACTIONS as $action => $definition) {
        $expected = $role === CompanyRole::Admin || $definition['defaults'][$role->value];

        expect($user->can(Catalog::actionPermission($action)))
            ->toBe($expected, "{$role->value} → action {$action}");
    }
})->with('roles');

test('the shared props carry the role’s pages and actions', function (CompanyRole $role) {
    $user = userWithRole($role);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentCompany.role', $role->value)
            ->where('can.pages.dashboard', $role !== CompanyRole::Member)
            ->where('can.pages.settings', $role === CompanyRole::Admin)
            ->where('can.actions', fn ($actions) => $actions['team.manage'] === ($role === CompanyRole::Admin)));
})->with('roles');

test('billing stays locked to Admins even if a role row holds it', function () {
    $user = userWithRole(CompanyRole::Partner);
    $company = companyOf($user);

    Role::query()
        ->where('name', 'partner')
        ->where('company_id', $company->id)
        ->firstOrFail()
        ->givePermissionTo(Catalog::pagePermission('billing'));

    app(CurrentCompany::class)->set($company, CompanyRole::Partner);

    expect($user->fresh()->can('page.billing'))->toBeFalse();
});

test('a user’s role is per company', function () {
    $first = Company::factory()->create();
    $second = Company::factory()->create();
    $user = userWithRole(CompanyRole::Admin, $first);
    app(SetCompanyRole::class)->handle($second, $user, CompanyRole::Viewer);

    app(CurrentCompany::class)->set($first, CompanyRole::Admin);
    expect($user->can('page.settings'))->toBeTrue();

    app(CurrentCompany::class)->set($second, CompanyRole::Viewer);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->can('page.settings'))->toBeFalse()
        ->and($user->can('page.reports'))->toBeTrue();
});

test('SetCompanyRole mirrors the role into spatie’s team roles', function () {
    $user = userWithRole(CompanyRole::Member);
    $company = companyOf($user);

    app(SetCompanyRole::class)->handle($company, $user, CompanyRole::Partner);

    setPermissionsTeamId($company->id);
    $user->unsetRelation('roles');

    expect($company->roleOf($user))->toBe(CompanyRole::Partner)
        ->and($user->getRoleNames()->all())->toBe(['partner']);
});
