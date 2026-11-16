<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\AccessRequest;
use App\Notifications\AccessRequested;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('landing sends each role to its first page', function (CompanyRole $role, string $route) {
    $user = userWithRole($role);

    $this->actingAs($user)->get(route('home'))->assertRedirect(route($route));
})->with([
    'admin' => [CompanyRole::Admin, 'dashboard'],
    'partner' => [CompanyRole::Partner, 'dashboard'],
    'viewer' => [CompanyRole::Viewer, 'dashboard'],
    // Until Timesheets ships, a Member lands on the next page they can open: Team.
    'member' => [CompanyRole::Member, 'team.index'],
]);

test('a direct visit to a page the role can’t open renders Permission denied', function () {
    $user = userWithRole(CompanyRole::Member, attributes: ['name' => 'Rohan Desai']);
    $admin = userWithRole(CompanyRole::Admin, companyOf($user), ['name' => 'Aarav Mehta']);
    $company = companyOf($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/403')
            ->where('page', 'dashboard')
            ->where('pageLabel', 'Dashboard')
            ->where('explanation', "The dashboard shows company-wide money, so in {$company->name} they’re for Admins, Partners and Viewers. You’re a Member, which covers your own hours, expenses and the vault items shared with you.")
            ->where('admins', ['Aarav Mehta'])
            ->where('request', null)
            // Timesheets ships in Phase 4; until then the way out is Profile.
            ->where('landing.label', 'Go to my profile')
            ->where('canOpen', fn ($items) => collect($items)->pluck('page')->all() === ['team', 'timesheets', 'expenses', 'vault', 'profile']));

    expect($admin)->not->toBeNull();
});

test('asking for access notifies the company’s Admins once', function () {
    Notification::fake();

    $user = userWithRole(CompanyRole::Member);
    $admin = userWithRole(CompanyRole::Admin, companyOf($user));
    userWithRole(CompanyRole::Partner, companyOf($user));

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('access-requests.store'), ['page' => 'dashboard', 'reason' => 'Checking hours billed to Asha Retail'])
        ->assertRedirect(route('dashboard'));

    $this->post(route('access-requests.store'), ['page' => 'dashboard']);

    Notification::assertSentTo($admin, AccessRequested::class);
    Notification::assertCount(1);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('request.reason', 'Checking hours billed to Asha Retail'));
});

test('an access request can be cancelled by the person who asked', function () {
    $user = userWithRole(CompanyRole::Member);
    $request = AccessRequest::query()->create(['company_id' => companyOf($user)->id, 'user_id' => $user->id, 'page' => 'reports']);

    $this->actingAs($user)->delete(route('access-requests.destroy', $request))->assertRedirect();

    expect($request->fresh()->cancelled_at)->not->toBeNull();
});

test('access requests are validated', function () {
    $user = userWithRole(CompanyRole::Member);

    $this->actingAs($user)
        ->post(route('access-requests.store'), ['page' => 'nowhere', 'reason' => str_repeat('x', 501)])
        ->assertSessionHasErrors(['page', 'reason']);
});
