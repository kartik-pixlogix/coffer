<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultType;
use App\Models\Company;
use App\Models\Vault;
use App\Models\VaultAuditEvent;
use App\Models\VaultGrant;
use App\Services\Vault\VaultAccess;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function shareAudit(Company $company): array
{
    return VaultAuditEvent::query()->withoutGlobalScopes()->where('company_id', $company->id)
        ->whereIn('action', ['shared', 'unshared'])->orderBy('id')->get()
        ->map(fn (VaultAuditEvent $e) => [$e->action->value, $e->meta['to_name'] ?? null, $e->meta['level'] ?? null, $e->meta['reason'] ?? $e->meta['via'] ?? null])->all();
}

test('a Manager shares an item, changes the level and removes it, and every step is audited', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $rohan = vaultUser(CompanyRole::Member, $company);
    $rohan->update(['name' => 'Rohan Desai']);
    $item = vaultItemFor($company, ['password' => 'demo-Share-Me-1!']);

    $panel = $this->actingAs($admin)->withSession(vaultUnlocked())->getJson(route('vault.items.share', $item))
        ->assertOk();
    expect(collect($panel->json('candidates'))->pluck('name'))->toContain('Rohan Desai')
        ->and(collect($panel->json('access'))->pluck('via')->all())->toBe(['admin']);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.grants.store', $item), ['user_ids' => [$rohan->id], 'level' => 'use', 'ends' => '7d'])
        ->assertOk()->assertJsonPath('message', 'Shared with Rohan Desai');

    $grant = VaultGrant::query()->withoutGlobalScopes()->where('user_id', $rohan->id)->sole();
    expect($grant->level)->toBe(VaultAccessLevel::Use)
        ->and($grant->vault_item_id)->toBe($item->id)
        ->and($grant->expires_at?->toDateTimeString())->toBe('2026-11-22 10:00:00')
        ->and($grant->granted_by)->toBe($admin->id);

    $row = collect($this->actingAs($admin)->withSession(vaultUnlocked())->getJson(route('vault.items.share', $item))->json('access'))->firstWhere('name', 'Rohan Desai');
    expect($row['via'])->toBe('item')->and($row['level'])->toBe('use')->and($row['editable'])->toBeTrue()->and($row['grantId'])->toBe($grant->id);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->patchJson(route('vault.grants.update', $grant), ['level' => 'edit'])
        ->assertOk()->assertJsonPath('message', 'Rohan Desai now has Edit access');

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->deleteJson(route('vault.grants.destroy', $grant))
        ->assertOk()->assertJsonPath('message', 'Rohan Desai no longer has access');

    expect(VaultGrant::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(shareAudit($company))->toBe([
            ['shared', 'Rohan Desai', 'use', 'share'],
            ['shared', 'Rohan Desai', 'edit', 'change'],
            ['unshared', 'Rohan Desai', 'edit', 'removed'],
        ]);
});

test('sharing a vault reaches its items, the role still caps the level, and restricted items stay out', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $viewer = vaultUser(CompanyRole::Viewer, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    $vault = vaultIn($company, 'Asha Retail');
    $item = vaultItemFor($company, ['password' => 'demo-x-1!'], [], $vault);
    $restricted = vaultItemFor($company, ['password' => 'demo-x-2!'], ['name' => 'AWS root', 'restricted' => true], $vault);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.vaults.grants.store', $vault), ['user_ids' => [$member->id], 'level' => 'manage', 'ends' => 'never'])
        ->assertOk();

    $access = app(VaultAccess::class);
    $access->flush();
    app(CurrentCompany::class)->set($company, null);

    expect($access->itemLevel($member, $item))->toBe(VaultAccessLevel::Manage)
        ->and($access->itemLevel($member, $restricted))->toBeNull();

    // A Viewer has no vault page, so there is no one to share with.
    $candidates = collect($this->actingAs($admin)->withSession(vaultUnlocked())->getJson(route('vault.vaults.share', $vault))->json('candidates'))->pluck('userId');
    expect($candidates)->not->toContain($viewer->id)->not->toContain($member->id);
});

test('only Managers and Admins share; locked sessions, Admin targets, outsiders and other companies are refused', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $otherAdmin = vaultUser(CompanyRole::Admin, $company);
    $partner = vaultUser(CompanyRole::Partner, $company);
    $outsider = vaultUser(CompanyRole::Member);
    $item = vaultItemFor($company, ['password' => 'demo-x-3!']);
    vaultGrant($partner, $item, VaultAccessLevel::Edit);

    $this->actingAs($partner)->withSession(vaultUnlocked())->getJson(route('vault.items.share', $item))->assertForbidden();
    $this->actingAs($partner)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.grants.store', $item), ['user_ids' => [$admin->id], 'level' => 'use', 'ends' => 'never'])->assertForbidden();

    $this->flushSession();
    $this->actingAs($admin)->getJson(route('vault.items.share', $item))->assertStatus(423);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.grants.store', $item), ['user_ids' => [$otherAdmin->id], 'level' => 'use', 'ends' => 'never'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.grants.store', $item), ['user_ids' => [$outsider->id], 'level' => 'use', 'ends' => 'never'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.grants.store', $item), ['user_ids' => [], 'level' => 'owner', 'ends' => 'forever'])
        ->assertUnprocessable()->assertJsonValidationErrors(['user_ids', 'level', 'ends']);

    // A Manager grant lets a partner share.
    VaultGrant::query()->withoutGlobalScopes()->update(['level' => 'manage']);
    app(VaultAccess::class)->flush();
    $this->actingAs($partner)->withSession(vaultUnlocked())->getJson(route('vault.items.share', $item))->assertOk();

    // Another company's item or grant is a 404.
    $elsewhere = Company::factory()->create();
    $foreign = vaultItemFor($elsewhere, ['password' => 'demo-x-4!']);
    $foreignGrant = vaultGrant(vaultUser(CompanyRole::Member, $elsewhere), $foreign, VaultAccessLevel::Use);
    $this->actingAs($admin)->withSession(vaultUnlocked())->getJson(route('vault.items.share', $foreign))->assertNotFound();
    $this->actingAs($admin)->withSession(vaultUnlocked())->deleteJson(route('vault.grants.destroy', $foreignGrant))->assertNotFound();
});

test('personal vaults can’t be shared', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = app(CurrentCompany::class)->run($company, fn () => Vault::query()->create(['name' => 'Mine', 'type' => VaultType::Personal, 'owner_id' => $admin->id]));
    $item = vaultItemFor($company, ['password' => 'demo-x-5!'], [], $vault);

    $this->actingAs($admin)->withSession(vaultUnlocked())->getJson(route('vault.items.share', $item))->assertForbidden();
    $this->actingAs($admin)->withSession(vaultUnlocked())->getJson(route('vault.vaults.share', $vault))->assertForbidden();
});

test('the scheduled expiry removes ended grants and audits them', function () {
    $company = Company::factory()->create();
    $member = vaultUser(CompanyRole::Member, $company);
    $member->update(['name' => 'Sana Qureshi']);
    $item = vaultItemFor($company, ['password' => 'demo-x-6!']);
    vaultGrant($member, $item, VaultAccessLevel::Use, now()->subMinute());
    vaultGrant($member, $item->vault, VaultAccessLevel::View, now()->addDay());

    $this->artisan('coffer:vault-expire-grants')->expectsOutput('Ended 1 vault grant.')->assertSuccessful();

    expect(VaultGrant::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(shareAudit($company))->toBe([['unshared', 'Sana Qureshi', 'use', 'expired']]);

    $event = VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'unshared')->sole();
    expect($event->user_id)->toBeNull()->and($event->vault_item_id)->toBe($item->id);
});
