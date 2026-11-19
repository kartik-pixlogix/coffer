<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Models\Company;
use App\Models\Member;
use App\Models\VaultAuditEvent;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('page access follows the matrix: Admins, Partners and Members open it, Viewers don’t', function (CompanyRole $role, int $status) {
    $company = Company::factory()->create();
    $this->actingAs(vaultUser($role, $company))->get(route('vault.index'))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 200],
    'member' => [CompanyRole::Member, 200],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('locked, the page shows the vaults but no items', function () {
    $company = Company::factory()->create();
    vaultItemFor($company, ['password' => 'demo-Locked-Away-1']);

    $this->actingAs(vaultUser(CompanyRole::Admin, $company))->get(route('vault.index'))
        ->assertInertia(fn (Assert $page) => $page->component('vault/index')
            ->where('vaultState.twoFactor', true)
            ->where('vaultState.unlocked', false)
            ->where('vaultState.policy.idleLockMinutes', 15)
            ->where('items', [])
            ->where('vaults.0.name', 'Internal')
            ->where('vaults.0.count', 1)
            ->where('health', null)
            ->has('can.pages'));
});

test('without 2FA the page asks to turn it on and lists nothing', function () {
    $company = Company::factory()->create();
    vaultItemFor($company);

    $this->actingAs(userWithRole(CompanyRole::Admin, $company))->withSession(vaultUnlocked())->get(route('vault.index'))
        ->assertInertia(fn (Assert $page) => $page->where('vaultState.twoFactor', false)->where('items', [])->where('abilities.create', false));
});

test('unlocked, each user sees only the items their access reaches, with levels and people', function () {
    $company = Company::factory()->create();
    $internal = vaultIn($company);
    $client = vaultIn($company, 'Asha Retail');
    $github = vaultItemFor($company, ['password' => 'demo-Visible-Pass-1'], [], $internal);
    vaultItemFor($company, [], ['name' => 'AWS — root account', 'restricted' => true], $internal);
    vaultItemFor($company, [], ['name' => 'Hostinger hPanel — Asha Retail', 'provider' => 'hostinger'], $client);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    vaultGrant($member, $internal, VaultAccessLevel::Use);

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.index'))
        ->assertInertia(fn (Assert $page) => $page->has('items', 3)->where('abilities.create', true)->where('abilities.newVault', true));

    $this->actingAs($member)->withSession(vaultUnlocked())->get(route('vault.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.id', $github->id)
            ->where('items.0.level', 'use')
            ->where('items.0.monogram', 'GH')
            ->where('items.0.subtitle', 'vertexlabs-admin')
            ->where('items.0.sharedWithMe', true)
            ->where('items.0.people.0.level', 'manage')
            ->where('items.0.people.1.you', true)
            ->has('vaults', 1)
            ->where('abilities.create', false));
});

test('?item= opens the drawer: fields without secrets, which secrets are set, activity — and audits a view', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Drawer-Pass-1', 'totp' => VAULT_TEST_2FA, 'notes' => 'demo drawer notes']);

    $response = $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.index', ['item' => $item->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $item->id)
            ->where('selected.primary', 'password')
            ->where('selected.notesSet', true)
            ->where('selected.hasTotp', true)
            ->where('selected.fields.1', ['key' => 'username', 'label' => 'Username or email', 'kind' => 'text', 'secret' => false, 'primary' => false, 'value' => 'vertexlabs-admin', 'set' => true])
            ->where('selected.fields.2.value', null)
            ->where('selected.fields.2.set', true)
            ->where('selected.abilities.reveal', true)
            ->where('selected.activity.0.text', 'You viewed this item')
            ->where('selected.activity.1.action', 'created'));

    expect($response->getContent())->not->toContain('demo-Drawer-Pass-1')->not->toContain('demo drawer notes')->not->toContain(VAULT_TEST_2FA)
        ->and(VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'viewed')->where('vault_item_id', $item->id)->count())->toBe(1);
});

test('an item from another company never opens', function () {
    $other = Company::factory()->create();
    $item = vaultItemFor($other);
    $company = Company::factory()->create();

    $this->actingAs(vaultUser(CompanyRole::Admin, $company))->withSession(vaultUnlocked())->get(route('vault.index', ['item' => $item->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected', null)->where('items', []));
});

test('the dashboard lists credentials expiring within 30 days for vault users only', function () {
    $books = singleBooks();
    $company = $books->company;
    vaultItemFor($company, [], ['name' => 'Vercel — team API token', 'type' => 'api_key', 'provider' => 'vercel', 'fields' => [], 'expires_at' => '2026-11-24']);
    vaultItemFor($company, [], ['name' => 'Domain saarthi-jv.in', 'type' => 'domain', 'provider' => null, 'fields' => ['domain' => 'saarthi-jv.in'], 'expires_at' => '2026-12-14']);
    vaultItemFor($company, [], ['name' => 'Later', 'expires_at' => '2027-03-01']);

    $this->actingAs(vaultUser(CompanyRole::Admin, $company))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('vault.items', 2)
            ->where('vault.items.0.name', 'Vercel — team API token')
            ->where('vault.items.0.days', 9)
            ->where('vault.items.0.initials', 'VC')
            ->where('vault.items.1.days', 29)
            ->where('vault.href', route('vault.index'))
            ->has('vault.healthScore'));

    // Viewers can't open the vault: no panel. Partners see only what they can reach.
    $this->actingAs(vaultUser(CompanyRole::Viewer, $company))->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('vault', null));
    $this->actingAs(vaultUser(CompanyRole::Partner, $company))->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('vault', null));
});

test('the member profile lists the vault items the member can open', function () {
    $books = singleBooks();
    $company = $books->company;
    $internal = vaultIn($company);
    $github = vaultItemFor($company, [], [], $internal);
    vaultItemFor($company, [], ['name' => 'Secret thing', 'restricted' => true], $internal);
    $rohan = vaultUser(CompanyRole::Member, $company);
    vaultGrant($rohan, $internal, VaultAccessLevel::Use);
    $member = app(CurrentCompany::class)->run($company, fn () => Member::factory()->for($books->aarav, 'partner')->create(['name' => 'Rohan Desai', 'user_id' => $rohan->id]));
    $unlinked = app(CurrentCompany::class)->run($company, fn () => Member::factory()->for($books->aarav, 'partner')->create(['name' => 'Neha Joshi']));

    $this->actingAs(vaultUser(CompanyRole::Admin, $company))->get(route('team.show', $member))
        ->assertInertia(fn (Assert $page) => $page->where('vaultAccess', ['items' => [['id' => $github->id, 'name' => 'GitHub — vertexlabs org', 'vault' => 'Internal', 'monogram' => 'GH', 'level' => 'use']], 'hidden' => 0]));

    $this->actingAs(vaultUser(CompanyRole::Admin, $company))->get(route('team.show', $unlinked))
        ->assertInertia(fn (Assert $page) => $page->where('vaultAccess', null));
});

test('with Use access the drawer lists only the viewer’s own activity; Manage sees everyone’s (X10)', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    $item = vaultItemFor($company, ['password' => 'demo-X10-Pass-1']);
    vaultGrant($member, $item, VaultAccessLevel::Use);

    // The Admin's view (with IP and device) is on the item first.
    $this->actingAs($admin)->withSession(vaultUnlocked())->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130')
        ->get(route('vault.index', ['item' => $item->id]))->assertOk();

    $this->actingAs($member)->withSession(vaultUnlocked())->get(route('vault.index', ['item' => $item->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('selected.activity', 1)
            ->where('selected.activity.0.text', 'You viewed this item'));

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.index', ['item' => $item->id]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.activity', fn ($a) => count($a) >= 3));
});
