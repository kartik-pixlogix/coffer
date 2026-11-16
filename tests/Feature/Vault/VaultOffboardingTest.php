<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultRequestStatus;
use App\Models\Company;
use App\Models\Member;
use App\Models\Partner;
use App\Models\VaultAccessRequest;
use App\Models\VaultAuditEvent;
use App\Models\VaultGrant;
use App\Models\VaultOffboarding;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('removing someone keeps the items they could use, takes their grants away and shows the rotate list', function () {
    $company = Company::factory()->create(['name' => 'Saarthi Portal JV']);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $rohan = vaultUser(CompanyRole::Member, $company);
    $rohan->update(['name' => 'Rohan Desai']);
    $github = vaultItemFor($company, ['password' => 'demo-Off-1!'], ['name' => 'GitHub — vertexlabs org']);
    $mysql = vaultItemFor($company, ['password' => 'demo-Off-2!'], ['name' => 'MySQL — production database'], vaultIn($company, 'Saarthi Portal JV'));
    $viewOnly = vaultItemFor($company, ['password' => 'demo-Off-3!'], ['name' => 'Cloudflare — DNS'], vaultIn($company, 'Asha Retail'));
    vaultGrant($rohan, $github->vault, VaultAccessLevel::Use);
    vaultGrant($rohan, $mysql, VaultAccessLevel::Edit);
    vaultGrant($rohan, $viewOnly, VaultAccessLevel::View);
    app(CurrentCompany::class)->run($company, fn () => VaultAccessRequest::query()->create([
        'user_id' => $rohan->id, 'vault_item_id' => $viewOnly->id, 'level' => 'use', 'duration' => '7d', 'reason' => 'need it', 'status' => 'pending',
    ]));

    $this->actingAs($admin)->get(route('settings.members'))
        ->assertInertia(fn (Assert $page) => $page->where('users', fn ($users) => collect($users)->firstWhere('name', 'Rohan Desai')['vaultItems'] === 2));

    $this->actingAs($admin)->delete(route('settings.members.destroy', $rohan))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Rohan Desai was removed from Saarthi Portal JV. They could use 2 vault items: rotate them from the list below.');

    $offboarding = VaultOffboarding::query()->withoutGlobalScopes()->sole();
    expect($offboarding->reason)->toBe('removed')
        ->and($offboarding->user_name)->toBe('Rohan Desai')
        ->and($offboarding->item_ids)->toEqualCanonicalizing([$github->id, $mysql->id])
        ->and(VaultGrant::query()->withoutGlobalScopes()->where('user_id', $rohan->id)->count())->toBe(0)
        ->and(VaultAccessRequest::query()->withoutGlobalScopes()->sole()->status)->toBe(VaultRequestStatus::Cancelled)
        ->and(VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'unshared')->get()->pluck('meta.reason')->unique()->all())->toBe(['left']);

    $this->actingAs($admin)->get(route('settings.members'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('offboardings.0.name', 'Rohan Desai')
            ->where('offboardings.0.reason', 'was removed')
            ->where('offboardings.0.total', 2)
            ->where('offboardings.0.open', 2)
            ->where('offboardings.0.items.0.name', 'GitHub — vertexlabs org')
            ->where('offboardings.0.items.0.canRotate', true));

    // Rotating an item ticks it off.
    CarbonImmutable::setTestNow('2026-11-16 09:00:00');
    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.items.update', $github), [
        'vault_id' => $github->vault_id, 'type' => 'login', 'name' => $github->name,
        'fields' => ['url' => 'https://github.com/login', 'username' => 'vertexlabs-admin'], 'secrets' => ['password' => 'demo-Off-New-1!'],
    ])->assertRedirect();

    $this->actingAs($admin)->get(route('settings.members'))
        ->assertInertia(fn (Assert $page) => $page->where('offboardings.0.open', 1)->where('offboardings.0.items.0.rotated', true));

    // Admins dismiss it; others can't.
    $partner = vaultUser(CompanyRole::Partner, $company);
    $this->actingAs($partner)->post(route('vault.offboardings.dismiss', $offboarding))->assertForbidden();
    $this->actingAs($admin)->post(route('vault.offboardings.dismiss', $offboarding))->assertRedirect();
    expect($offboarding->refresh()->dismissed_at)->not->toBeNull();

    // Another company's list is a 404.
    $elsewhere = app(CurrentCompany::class)->run($other = Company::factory()->create(), fn () => VaultOffboarding::query()->create([
        'user_name' => 'X', 'reason' => 'removed', 'item_ids' => [],
    ]));
    unset($other);
    $this->actingAs($admin)->post(route('vault.offboardings.dismiss', $elsewhere))->assertNotFound();
});

test('a role change that takes vault access away lists what they lose; deactivating a linked member does too', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $kabir = vaultUser(CompanyRole::Partner, $company);
    $kabir->update(['name' => 'Kabir Shah']);
    $item = vaultItemFor($company, ['password' => 'demo-Off-4!']);
    vaultGrant($kabir, $item, VaultAccessLevel::Manage);

    // Viewer has no vault page.
    $this->actingAs($admin)->patch(route('settings.members.role', $kabir), ['role' => 'viewer'])
        ->assertRedirect()->assertInertiaFlash('toast.message', 'Kabir Shah is now a Viewer. They could use 1 vault item: rotate it from the list below.');
    expect(VaultOffboarding::query()->withoutGlobalScopes()->sole()->reason)->toBe('role');

    // A role change that keeps access writes nothing.
    $this->actingAs($admin)->patch(route('settings.members.role', $kabir), ['role' => 'partner'])->assertRedirect();
    $this->actingAs($admin)->patch(route('settings.members.role', $kabir), ['role' => 'member'])->assertRedirect();
    expect(VaultOffboarding::query()->withoutGlobalScopes()->count())->toBe(1);

    $partner = app(CurrentCompany::class)->run($company, fn () => Partner::factory()->for($company)->create());
    $member = app(CurrentCompany::class)->run($company, fn () => Member::factory()->for($company)->create(['partner_id' => $partner->id, 'user_id' => $kabir->id, 'name' => 'Kabir Shah']));

    $this->actingAs($admin)->patch(route('team.status', $member))
        ->assertRedirect()->assertInertiaFlash('toast.message', 'Kabir Shah deactivated. Their history stays. They could use 1 vault item: rotate it from Security health.');
    expect(VaultOffboarding::query()->withoutGlobalScopes()->latest('id')->first()->reason)->toBe('deactivated');
});
