<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Side;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

test('the roles page shows the spec matrix', function () {
    $this->actingAs(userWithRole())
        ->get(route('settings.roles'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/roles')
            ->has('pages', count(Catalog::PAGES))
            ->has('actions', count(Catalog::ACTIONS))
            ->where('matrix.viewer', fn ($granted) => collect($granted)->sort()->values()->all() === collect(Catalog::defaultsFor(CompanyRole::Viewer))->sort()->values()->all())
            ->where('canEdit', true));
});

test('saving the matrix changes what a role can open', function () {
    $admin = userWithRole();
    $company = companyOf($admin);
    $viewer = userWithRole(CompanyRole::Viewer, $company);

    $this->actingAs($admin)->put(route('settings.roles.update'), ['matrix' => [
        'partner' => Catalog::defaultsFor(CompanyRole::Partner),
        'member' => Catalog::defaultsFor(CompanyRole::Member),
        'viewer' => ['page.dashboard', 'page.income', 'page.billing'],
    ]])->assertRedirect();

    app(CurrentCompany::class)->set($company, CompanyRole::Viewer);
    $viewer = $viewer->fresh();

    expect($viewer->can('page.income'))->toBeTrue()
        ->and($viewer->can('page.reports'))->toBeFalse()
        // Billing is locked: it can't be granted (D29).
        ->and($viewer->can('page.billing'))->toBeFalse();
});

test('reset puts the defaults back', function () {
    $admin = userWithRole();
    $company = companyOf($admin);
    Role::query()->where('company_id', $company->id)->where('name', 'viewer')->firstOrFail()->syncPermissions([]);

    $this->actingAs($admin)->post(route('settings.roles.reset'))->assertRedirect();

    expect(Role::query()->where('company_id', $company->id)->where('name', 'viewer')->firstOrFail()->permissions()->count())
        ->toBe(count(Catalog::defaultsFor(CompanyRole::Viewer)));
});

test('only Admins change the matrix, even with the Settings page', function () {
    $partner = userWithRole(CompanyRole::Partner);
    Role::query()->where('company_id', companyOf($partner)->id)->where('name', 'partner')->firstOrFail()->givePermissionTo('page.settings');

    $this->actingAs($partner)->put(route('settings.roles.update'), ['matrix' => ['partner' => Catalog::all(), 'member' => [], 'viewer' => []]])->assertForbidden();
    $this->actingAs($partner)->post(route('settings.roles.reset'))->assertForbidden();
});

test('the matrix rejects unknown permissions', function () {
    $this->actingAs(userWithRole())
        ->put(route('settings.roles.update'), ['matrix' => ['partner' => ['page.everything'], 'member' => [], 'viewer' => []]])
        ->assertSessionHasErrors('matrix.partner.0');
});

/** Vertex Labs: Aarav 60%, Ishita 40%. */
function vertexLabs(): array
{
    $admin = userWithRole(attributes: ['name' => 'Aarav Mehta']);
    $company = companyOf($admin);
    $aarav = Partner::factory()->for($company)->create(['name' => 'Aarav Mehta', 'user_id' => $admin->id, 'role' => CompanyRole::Admin, 'colour' => 1, 'equity_bps' => 6000]);
    $ishita = Partner::factory()->for($company)->create(['name' => 'Ishita Rao', 'colour' => 2, 'equity_bps' => 4000]);

    return [$admin, $company, $aarav, $ishita];
}

test('the partners page shows each partner’s share', function () {
    [$admin] = vertexLabs();

    $this->actingAs($admin)
        ->get(route('settings.partners'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/partners')
            ->where('partners.0.name', 'Aarav Mehta')
            ->where('partners.0.overallBps', 6000)
            ->where('partners.1.equityBps', 4000));
});

test('editing a share saves the rebalanced split together (D31)', function () {
    [$admin, , $aarav, $ishita] = vertexLabs();

    $this->actingAs($admin)
        ->put(route('settings.partners.update', $ishita), [
            'name' => 'Ishita Rao', 'role' => 'partner', 'colour' => 2, 'equity' => '45', 'shares' => [$aarav->id => '55'],
        ])
        ->assertSessionHasNoErrors();

    expect($ishita->fresh()->equity_bps)->toBe(4500)
        ->and($aarav->fresh()->equity_bps)->toBe(5500);
});

test('a split that doesn’t total 100% is refused with the gap', function () {
    [$admin, , , $ishita] = vertexLabs();

    $this->actingAs($admin)
        ->put(route('settings.partners.update', $ishita), ['name' => 'Ishita Rao', 'role' => 'partner', 'colour' => 2, 'equity' => '35'])
        ->assertSessionHasErrors(['equity' => 'Equity adds up to 95.00%. Add 5.00% so it totals 100%.']);

    expect($ishita->fresh()->equity_bps)->toBe(4000);
});

test('adding a partner rebalances and can invite them', function () {
    [$admin, , $aarav, $ishita] = vertexLabs();

    $this->actingAs($admin)->post(route('settings.partners.store'), [
        'name' => 'Kabir Shah', 'email' => 'kabir@bluefininfotech.in', 'role' => 'partner', 'colour' => 3,
        'equity' => '20', 'shares' => [$aarav->id => '50', $ishita->id => '30'],
    ])->assertSessionHasNoErrors();

    $kabir = Partner::query()->withoutGlobalScopes()->where('name', 'Kabir Shah')->sole();
    expect($kabir->equity_bps)->toBe(2000)
        ->and(Invite::query()->withoutGlobalScopes()->where('partner_id', $kabir->id)->exists())->toBeTrue();
});

test('a partner with a share can’t be removed; one with records is archived', function () {
    [$admin, $company, $aarav, $ishita] = vertexLabs();

    $this->actingAs($admin)->delete(route('settings.partners.destroy', $ishita))
        ->assertSessionHasErrors(['partner' => 'Move Ishita Rao’s 40% to another partner before removing them.']);

    $ishita->update(['equity_bps' => 0]);
    $aarav->update(['equity_bps' => 10000]);
    Member::factory()->for($company)->create(['partner_id' => $ishita->id]);

    $this->delete(route('settings.partners.destroy', $ishita))->assertSessionHasNoErrors();
    expect($ishita->fresh()->archived_at)->not->toBeNull();

    $spare = Partner::factory()->for($company)->create(['equity_bps' => 0]);
    $this->delete(route('settings.partners.destroy', $spare));
    expect(Partner::query()->withoutGlobalScopes()->find($spare->id))->toBeNull();
});

test('another company’s partner can’t be edited', function () {
    [$admin] = vertexLabs();
    $foreign = Partner::factory()->create();

    $this->actingAs($admin)
        ->put(route('settings.partners.update', $foreign), ['name' => 'X', 'role' => 'partner', 'colour' => 1, 'equity' => '100'])
        ->assertNotFound();
});

/** Saarthi: two sides at 50%, two partners each at 50% of their side. */
function saarthi(): array
{
    $company = Company::factory()->jv()->create(['name' => 'Saarthi Portal JV']);
    $admin = userWithRole(CompanyRole::Admin, $company);
    $vertex = Side::factory()->for($company)->create(['name' => 'Vertex Labs', 'equity_bps' => 5000, 'position' => 0]);
    $bluefin = Side::factory()->for($company)->create(['name' => 'Bluefin Infotech', 'equity_bps' => 5000, 'position' => 1]);
    Partner::factory()->for($company)->create(['name' => 'Aarav Mehta', 'side_id' => $vertex->id, 'equity_bps' => 5000]);
    Partner::factory()->for($company)->create(['name' => 'Ishita Rao', 'side_id' => $vertex->id, 'equity_bps' => 5000]);
    $kabir = Partner::factory()->for($company)->create(['name' => 'Kabir Shah', 'side_id' => $bluefin->id, 'equity_bps' => 5000]);
    $meera = Partner::factory()->for($company)->create(['name' => 'Meera Iyer', 'side_id' => $bluefin->id, 'equity_bps' => 5000]);

    return [$admin, $company, $vertex, $bluefin, $kabir, $meera];
}

test('JV partner edits are checked per side', function () {
    [$admin, , , $bluefin, $kabir, $meera] = saarthi();

    $this->actingAs($admin)
        ->put(route('settings.partners.update', $meera), ['name' => 'Meera Iyer', 'role' => 'partner', 'colour' => 4, 'side_id' => $bluefin->id, 'equity' => '40'])
        ->assertSessionHasErrors(['equity' => 'Bluefin Infotech partners add up to 90.00%. Add 10.00% so this side totals 100%.']);

    $this->put(route('settings.partners.update', $meera), ['name' => 'Meera Iyer', 'role' => 'partner', 'colour' => 4, 'side_id' => $bluefin->id, 'equity' => '40', 'shares' => [$kabir->id => '60']])
        ->assertSessionHasNoErrors();
});

test('sides must total 100% and keep their partners', function () {
    [$admin, , $vertex, $bluefin] = saarthi();

    $this->actingAs($admin)->get(route('settings.sides'))->assertInertia(fn (Assert $page) => $page->component('settings/sides')->has('sides', 2));

    $this->put(route('settings.sides.update'), ['sides' => [
        ['id' => $vertex->id, 'name' => 'Vertex Labs', 'equity' => '60'],
        ['id' => $bluefin->id, 'name' => 'Bluefin Infotech', 'equity' => '30'],
    ]])->assertSessionHasErrors(['sides' => 'Side equity must add up to 100%. It’s 90% now.']);

    $this->put(route('settings.sides.update'), ['sides' => [
        ['id' => $vertex->id, 'name' => 'Vertex Labs', 'equity' => '60'],
        ['id' => null, 'name' => 'Third side', 'equity' => '40'],
    ]])->assertSessionHasErrors(['sides' => 'Bluefin Infotech still has partners. Move them to another side first.']);

    $this->put(route('settings.sides.update'), ['sides' => [
        ['id' => $vertex->id, 'name' => 'Vertex Labs', 'equity' => '60'],
        ['id' => $bluefin->id, 'name' => 'Bluefin Infotech', 'equity' => '40'],
    ]])->assertSessionHasNoErrors();

    expect($vertex->fresh()->equity_bps)->toBe(6000);
});

test('a Single company has no sides page', function () {
    $this->actingAs(userWithRole())->get(route('settings.sides'))->assertRedirect(route('settings.partners'));
});
