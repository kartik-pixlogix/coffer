<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Enums\InviteKind;
use App\Models\Company;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Partner;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CompanyInvitation;
use App\Support\Permissions\Catalog;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/** An Admin of a company whose workspace someone else owns (so the Admin isn't the owner). */
function settingsAdmin(): User
{
    $owner = User::factory()->create();
    $company = Company::factory()->create(['workspace_id' => Workspace::factory()->create(['owner_id' => $owner->id])->id]);
    app(SetCompanyRole::class)->handle($company, $owner, CompanyRole::Admin);

    return userWithRole(CompanyRole::Admin, $company, ['name' => 'Aarav Mehta']);
}

/** Grant the Settings page to Partners in `$company`. */
function grantSettingsToPartners(Company $company): void
{
    Role::query()->where('company_id', $company->id)->where('name', 'partner')->firstOrFail()
        ->givePermissionTo(Catalog::pagePermission('settings'));
}

test('only roles with the Settings page can open settings', function (CompanyRole $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('settings.members'))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 403],
    'member' => [CompanyRole::Member, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('the members page lists users with role, link and join date, and the team by partner', function () {
    $admin = settingsAdmin();
    $company = companyOf($admin);
    $kabir = userWithRole(CompanyRole::Partner, $company, ['name' => 'Kabir Shah']);
    $partner = Partner::factory()->for($company)->create(['name' => 'Kabir Shah', 'user_id' => $kabir->id, 'colour' => 3]);
    Member::factory()->for($company)->create(['partner_id' => $partner->id, 'name' => 'Rohan Desai']);

    $this->actingAs($admin)
        ->get(route('settings.members'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/members')
            ->has('users', 3)
            ->where('users', fn ($users) => collect($users)->firstWhere('name', 'Kabir Shah')['linked'] === ['label' => 'Kabir Shah', 'kind' => 'Partner']
                && collect($users)->firstWhere('name', 'Aarav Mehta')['you'] === true)
            ->where('team.0.partner.name', 'Kabir Shah')
            ->where('team.0.members.0.name', 'Rohan Desai'));
});

test('an Admin changes someone’s role', function () {
    $admin = settingsAdmin();
    $company = companyOf($admin);
    $kabir = userWithRole(CompanyRole::Member, $company);

    $this->actingAs($admin)
        ->patch(route('settings.members.role', $kabir), ['role' => 'partner'])
        ->assertRedirect();

    expect($company->roleOf($kabir))->toBe(CompanyRole::Partner);
});

test('nobody changes their own role, the owner stays Admin, and the last Admin stays', function () {
    $admin = settingsAdmin();
    $company = companyOf($admin);
    $owner = $company->workspace->owner;

    $this->actingAs($admin)->patch(route('settings.members.role', $admin), ['role' => 'viewer'])->assertForbidden();
    $this->actingAs($admin)->patch(route('settings.members.role', $owner), ['role' => 'viewer'])
        ->assertSessionHasErrors(['role' => "{$owner->name} owns the workspace, so they stay an Admin."]);

    // A second admin can be demoted while another remains.
    $other = userWithRole(CompanyRole::Admin, $company);
    $this->actingAs($admin)->patch(route('settings.members.role', $other), ['role' => 'partner'])->assertSessionHasNoErrors();
});

test('someone given the Settings page can’t hand out Admin', function () {
    $partner = userWithRole(CompanyRole::Partner);
    $company = companyOf($partner);
    grantSettingsToPartners($company);
    $member = userWithRole(CompanyRole::Member, $company);

    $this->actingAs($partner)->get(route('settings.members'))->assertOk();
    $this->actingAs($partner)->patch(route('settings.members.role', $member), ['role' => 'admin'])->assertForbidden();
    $this->actingAs($partner)->patch(route('settings.members.role', $member), ['role' => 'viewer'])->assertRedirect();

    expect($company->roleOf($member))->toBe(CompanyRole::Viewer);
});

test('removing someone ends their membership and unlinks their records', function () {
    $admin = settingsAdmin();
    $company = companyOf($admin);
    $kabir = userWithRole(CompanyRole::Partner, $company);
    $partner = Partner::factory()->for($company)->create(['user_id' => $kabir->id]);

    $this->actingAs($admin)->delete(route('settings.members.destroy', $kabir))->assertRedirect();

    expect($company->roleOf($kabir))->toBeNull()
        ->and($partner->fresh()->user_id)->toBeNull();
});

test('people in other companies can’t be changed from here', function () {
    $admin = settingsAdmin();
    $stranger = userWithRole(CompanyRole::Member);

    $this->actingAs($admin)->patch(route('settings.members.role', $stranger), ['role' => 'viewer'])->assertNotFound();
    $this->actingAs($admin)->delete(route('settings.members.destroy', $stranger))->assertNotFound();
});

test('an Admin resets someone’s two-factor authentication', function () {
    $admin = settingsAdmin();
    $kabir = userWithRole(CompanyRole::Partner, companyOf($admin), ['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()]);

    $this->actingAs($admin)->post(route('settings.members.reset-two-factor', $kabir))->assertRedirect();

    expect($kabir->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('invites go out by email with a role, an expiry and an optional link', function () {
    Notification::fake();
    $admin = settingsAdmin();
    $company = companyOf($admin);
    $member = Member::factory()->for($company)->create(['partner_id' => Partner::factory()->for($company)->create()->id]);

    $this->actingAs($admin)
        ->post(route('settings.invites.store'), ['emails' => 'priya@vertexlabs.in', 'role' => 'member', 'link' => "member:{$member->id}", 'days' => 3])
        ->assertSessionHasNoErrors();

    $invite = Invite::query()->withoutGlobalScopes()->where('email', 'priya@vertexlabs.in')->sole();
    expect($invite->member_id)->toBe($member->id)
        ->and($invite->role)->toBe(CompanyRole::Member)
        ->and($invite->expires_at->isSameDay(now()->addDays(3)))->toBeTrue();

    $this->post(route('settings.invites.store'), ['emails' => 'a@x.in, b@x.in', 'role' => 'viewer', 'days' => 7])->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(CompanyInvitation::class, 3);
});

test('invites are validated', function (array $input, string $field) {
    $admin = settingsAdmin();
    userWithRole(CompanyRole::Viewer, companyOf($admin), ['email' => 'taken@vertexlabs.in']);

    $this->actingAs($admin)
        ->post(route('settings.invites.store'), ['role' => 'member', 'days' => 7, ...$input])
        ->assertSessionHasErrors($field);
})->with([
    'bad email' => [['emails' => 'priya@'], 'emails'],
    'already in' => [['emails' => 'taken@vertexlabs.in'], 'emails'],
    'odd expiry' => [['emails' => 'p@x.in', 'days' => 30], 'days'],
    'link two people' => [['emails' => 'p@x.in, q@x.in', 'link' => 'member:1'], 'link'],
]);

test('only Admins invite Admins', function () {
    $partner = userWithRole(CompanyRole::Partner);
    grantSettingsToPartners(companyOf($partner));

    $this->actingAs($partner)
        ->post(route('settings.invites.store'), ['emails' => 'p@x.in', 'role' => 'admin', 'days' => 7])
        ->assertForbidden();
});

test('a new share link replaces the old one; invites can be resent and revoked', function () {
    Notification::fake();
    $admin = settingsAdmin();

    $this->actingAs($admin)->post(route('settings.invites.link'), ['role' => 'member', 'days' => 7]);
    $first = Invite::query()->withoutGlobalScopes()->where('kind', InviteKind::Link->value)->sole();
    $this->post(route('settings.invites.link'), ['role' => 'viewer', 'days' => 14]);

    expect($first->fresh()->revoked_at)->not->toBeNull()
        ->and(Invite::query()->withoutGlobalScopes()->pending()->where('kind', 'link')->value('role'))->toBe(CompanyRole::Viewer);

    $invite = Invite::factory()->for(companyOf($admin))->create(['invited_by' => $admin->id]);
    $this->post(route('settings.invites.resend', $invite))->assertRedirect();
    Notification::assertSentOnDemand(CompanyInvitation::class);

    $this->delete(route('settings.invites.destroy', $invite))->assertRedirect();
    expect($invite->fresh()->revoked_at)->not->toBeNull();
});

test('another company’s invite can’t be revoked from here', function () {
    $admin = settingsAdmin();
    $foreign = Invite::factory()->create();

    $this->actingAs($admin)->delete(route('settings.invites.destroy', $foreign))->assertNotFound();
    expect($foreign->fresh()->revoked_at)->toBeNull();
});
