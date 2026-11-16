<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('admins, partners and viewers can open the dashboard', function (CompanyRole $role) {
    $this->actingAs(userWithRole($role))
        ->get(route('dashboard'))
        ->assertOk();
})->with([CompanyRole::Admin, CompanyRole::Partner, CompanyRole::Viewer]);

test('members can’t open the dashboard (spec §2.1)', function () {
    $this->actingAs(userWithRole(CompanyRole::Member))
        ->get(route('dashboard'))
        ->assertForbidden();
});

test('the dashboard renders inside the coffer shell with the user', function () {
    $user = userWithRole(CompanyRole::Admin, attributes: ['name' => 'Aarav Mehta']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.user.name', 'Aarav Mehta')
            ->where('auth.user.theme', null)
            ->where('currentCompany.id', companyOf($user)->id));
});

test('unverified users cannot reach the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});
