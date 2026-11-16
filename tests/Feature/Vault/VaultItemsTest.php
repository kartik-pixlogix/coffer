<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Models\Company;
use App\Models\Vault;
use App\Models\VaultAuditEvent;
use App\Models\VaultFavourite;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Models\VaultItemVersion;
use App\Models\VaultSecret;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\VaultAccess;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function itemPayload(Vault $vault, array $overrides = []): array
{
    return array_replace([
        'vault_id' => $vault->id,
        'type' => 'login',
        'provider' => 'gitlab',
        'name' => 'GitLab — saarthi-portal group',
        'environment' => 'production',
        'tags' => ['Client: Saarthi', 'deploy'],
        'fields' => ['url' => 'https://gitlab.com/users/sign_in', 'username' => 'deploy@saarthi-jv.in'],
        'secrets' => ['password' => 'demo-GitLab-Pass-2026!', 'totp' => 'JBSW Y3DP EHPK 3PXP', 'notes' => 'Shared deploy account.'],
        'custom_fields' => [['label' => 'Group ID', 'secret' => false, 'value' => '4471'], ['label' => 'Deploy token', 'secret' => true, 'value' => 'demo-deploy-token-9']],
        'strength' => 4,
    ], $overrides);
}

function secretsOf(VaultItem $item): array
{
    return app(CurrentCompany::class)->run($item->company()->firstOrFail(), fn () => app(ItemSecrets::class)->all($item->fresh()));
}

function soleItem(Company $company): VaultItem
{
    return VaultItem::query()->withoutGlobalScopes()->where('company_id', $company->id)->latest('id')->firstOrFail();
}

test('create permission: Admins, and Partners with Edit on the vault; not Members or Viewers', function (CompanyRole $role, ?VaultAccessLevel $grant, bool $allowed) {
    $company = Company::factory()->create();
    $vault = vaultIn($company);
    $user = vaultUser($role, $company);

    if ($grant !== null) {
        vaultGrant($user, $vault, $grant);
    }

    $response = $this->actingAs($user)->withSession(vaultUnlocked())->post(route('vault.items.store'), itemPayload($vault));

    $allowed
        ? $response->assertRedirect(route('vault.index', ['item' => soleItem($company)->id]))->assertInertiaFlash('toast.message', 'GitLab — saarthi-portal group saved to Internal')
        : $response->assertForbidden();
})->with([
    'admin' => [CompanyRole::Admin, null, true],
    'partner with edit' => [CompanyRole::Partner, VaultAccessLevel::Edit, true],
    'partner with use' => [CompanyRole::Partner, VaultAccessLevel::Use, false],
    'partner without a grant' => [CompanyRole::Partner, null, false],
    'member' => [CompanyRole::Member, VaultAccessLevel::Manage, false],
]);

test('viewers can’t open the vault pages', function () {
    $company = Company::factory()->create();
    $viewer = vaultUser(CompanyRole::Viewer, $company);

    $this->actingAs($viewer)->get(route('vault.index'))->assertForbidden();
    $this->actingAs($viewer)->get(route('vault.items.create'))->assertForbidden();
    $this->actingAs($viewer)->post(route('vault.items.store'), itemPayload(vaultIn($company)))->assertForbidden();
});

test('saving classifies fields: secrets are encrypted and fingerprinted, metadata stays plain', function () {
    $company = Company::factory()->create();
    $vault = vaultIn($company);
    $admin = vaultUser(CompanyRole::Admin, $company);

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.items.store'), itemPayload($vault))->assertSessionHasNoErrors();
    $item = soleItem($company);

    expect($item->name)->toBe('GitLab — saarthi-portal group')
        ->and($item->username)->toBe('deploy@saarthi-jv.in')
        ->and($item->subtitle)->toBe('deploy@saarthi-jv.in')
        ->and($item->tags)->toBe(['client: saarthi', 'deploy'])
        ->and($item->has_totp)->toBeTrue()
        ->and($item->strength)->toBe(4)
        ->and($item->rotated_at?->toDateTimeString())->toBe('2026-11-15 10:00:00')
        ->and($item->version)->toBe(1)
        ->and(json_encode($item->getAttributes()))->not->toContain('demo-GitLab-Pass-2026!')
        ->and(json_encode($item->custom_fields))->not->toContain('demo-deploy-token-9');

    $rows = VaultSecret::query()->withoutGlobalScopes()->where('vault_item_id', $item->id)->get();
    $customId = collect($item->custom_fields)->firstWhere('label', 'Deploy token')['id'];

    expect($rows->pluck('field')->sort()->values()->all())->toBe(["custom.{$customId}", 'notes', 'password', 'totp'])
        ->and($rows->pluck('ciphertext')->implode(' '))->not->toContain('demo-GitLab')
        ->and($rows->firstWhere('field', 'password')->fingerprint)->toHaveLength(64)
        ->and($rows->firstWhere('field', 'notes')->fingerprint)->toBeNull()
        ->and(secretsOf($item))->toMatchArray(['password' => 'demo-GitLab-Pass-2026!', 'totp' => 'JBSWY3DPEHPK3PXP', 'notes' => 'Shared deploy account.']);

    // The creator's activity-log row names fields, never values; the audit log has "created".
    $activity = Activity::query()->where('log_name', 'vault')->latest('id')->firstOrFail();
    expect(json_encode($activity->properties))->not->toContain('demo-GitLab')
        ->and(VaultAuditEvent::query()->withoutGlobalScopes()->where('vault_item_id', $item->id)->pluck('action')->map->value->all())->toBe(['created']);
});

test('validation: required fields, template keys only, setup keys and attachment size', function () {
    $company = Company::factory()->create();
    $vault = vaultIn($company);
    $admin = vaultUser(CompanyRole::Admin, $company);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->post(route('vault.items.store'), itemPayload($vault, ['name' => '', 'fields' => ['password' => 'plain?', 'nonsense' => 'x'], 'secrets' => ['username' => 'x', 'totp' => 'not-base32!!'], 'type' => 'login']))
        ->assertSessionHasErrors(['name', 'fields.password', 'fields.nonsense', 'secrets.username', 'secrets.totp']);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->post(route('vault.items.store'), itemPayload($vault, ['type' => 'database', 'fields' => ['engine' => 'Oracle', 'port' => 'abc'], 'secrets' => []]))
        ->assertSessionHasErrors(['fields.engine', 'fields.port']);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->post(route('vault.items.store'), itemPayload($vault, ['attachments' => [UploadedFile::fake()->create('big.bin', 6000)]]))
        ->assertSessionHasErrors(['attachments.0' => 'Attachments can be up to 5 MB each.']);

    $other = Company::factory()->create();
    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->post(route('vault.items.store'), itemPayload(vaultIn($other)))
        ->assertSessionHasErrors('vault_id');
});

test('edit keeps secrets left empty, replaces typed ones, clears on request, and versions every save', function () {
    $company = Company::factory()->create();
    $vault = vaultIn($company);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.items.store'), itemPayload($vault));
    $item = soleItem($company);
    $custom = collect($item->custom_fields)->map(fn (array $c) => [...$c, 'value' => $c['secret'] ? '' : ($c['value'] ?? '')])->all();

    CarbonImmutable::setTestNow('2026-11-16 09:00:00');

    // Only the name changes: secrets stay.
    $this->actingAs($admin)->put(route('vault.items.update', $item), itemPayload($vault, ['name' => 'GitLab — deploy', 'secrets' => [], 'custom_fields' => $custom, 'strength' => null]))
        ->assertRedirect(route('vault.index', ['item' => $item->id]))->assertInertiaFlash('toast.message', 'GitLab — deploy saved');
    expect(secretsOf($item))->toHaveKey('password', 'demo-GitLab-Pass-2026!')->and($item->fresh()->version)->toBe(2)
        ->and($item->fresh()->rotated_at?->toDateString())->toBe('2026-11-15');

    // A new password rotates; clearing the TOTP key removes it.
    $this->actingAs($admin)->put(route('vault.items.update', $item), itemPayload($vault, ['name' => 'GitLab — deploy', 'secrets' => ['password' => 'weak'], 'clear' => ['totp'], 'custom_fields' => $custom, 'strength' => 0]));
    $fresh = $item->fresh();
    expect(secretsOf($item))->toHaveKey('password', 'weak')->not->toHaveKey('totp')
        ->and(secretsOf($item))->toHaveKey('custom.'.$custom[1]['id'], 'demo-deploy-token-9')
        ->and($fresh->has_totp)->toBeFalse()
        ->and($fresh->strength)->toBe(0)
        ->and($fresh->rotated_at?->toDateString())->toBe('2026-11-16')
        ->and($fresh->version)->toBe(3)
        ->and(VaultItemVersion::query()->withoutGlobalScopes()->where('vault_item_id', $item->id)->pluck('reason')->all())->toBe(['created', 'edited', 'rotated']);

    // Saving unchanged changes nothing.
    $this->actingAs($admin)->put(route('vault.items.update', $item), itemPayload($vault, ['name' => 'GitLab — deploy', 'secrets' => [], 'custom_fields' => $custom, 'strength' => null]));
    expect($item->fresh()->version)->toBe(3);
});

test('restore brings an old version back as a new one, audited', function () {
    $company = Company::factory()->create();
    $vault = vaultIn($company);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.items.store'), itemPayload($vault));
    $item = soleItem($company);
    $this->actingAs($admin)->put(route('vault.items.update', $item), itemPayload($vault, ['name' => 'Renamed', 'secrets' => ['password' => 'demo-second-password-1']]));

    $this->actingAs($admin)->post(route('vault.items.restore', ['item' => $item, 'version' => 1]))
        ->assertRedirect(route('vault.index', ['item' => $item->id]))->assertInertiaFlash('toast.message', 'Version 1 restored as version 3');

    $fresh = $item->fresh();
    expect($fresh->name)->toBe('GitLab — saarthi-portal group')
        ->and($fresh->version)->toBe(3)
        ->and(secretsOf($item)['password'])->toBe('demo-GitLab-Pass-2026!')
        ->and(VaultAuditEvent::query()->withoutGlobalScopes()->where('vault_item_id', $item->id)->pluck('action')->map->value->all())->toBe(['created', 'rotated', 'restored']);

    $history = $this->actingAs($admin)->getJson(route('vault.items.history', $item))->assertOk();
    expect($history->json('versions.0'))->toMatchArray(['version' => 3, 'reason' => 'restored', 'restoredFrom' => 1, 'current' => true])
        ->and($history->getContent())->not->toContain('demo-GitLab-Pass-2026!');
});

test('only Edit can change or restore, only Manage can delete; another company’s item is a 404', function () {
    $company = Company::factory()->create();
    $vault = vaultIn($company);
    $item = vaultItemFor($company, ['password' => 'demo-Pass-word-123']);
    $user = vaultUser(CompanyRole::Partner, $company);
    vaultGrant($user, $vault, VaultAccessLevel::Use);

    $this->actingAs($user)->withSession(vaultUnlocked())->get(route('vault.items.edit', $item))->assertForbidden();
    $this->actingAs($user)->put(route('vault.items.update', $item), itemPayload($vault))->assertForbidden();
    $this->actingAs($user)->post(route('vault.items.restore', ['item' => $item, 'version' => 1]))->assertForbidden();
    $this->actingAs($user)->delete(route('vault.items.destroy', $item))->assertForbidden();

    VaultGrant::query()->withoutGlobalScopes()->where('user_id', $user->id)->update(['level' => 'edit']);
    app(VaultAccess::class)->flush();
    $this->actingAs($user)->delete(route('vault.items.destroy', $item))->assertForbidden();

    $admin = vaultUser(CompanyRole::Admin, $company);
    $this->actingAs($admin)->delete(route('vault.items.destroy', $item))->assertRedirect(route('vault.index'))
        ->assertInertiaFlash('toast.message', 'GitHub — vertexlabs org deleted');
    expect($item->fresh()->trashed())->toBeTrue()
        ->and(VaultAuditEvent::query()->withoutGlobalScopes()->where('vault_item_id', $item->id)->pluck('action')->map->value->all())->toContain('deleted');

    $outsider = vaultUser(CompanyRole::Admin);
    $live = vaultItemFor($company);
    foreach ([['get', 'vault.items.edit'], ['put', 'vault.items.update'], ['delete', 'vault.items.destroy'], ['post', 'vault.items.favourite'], ['get', 'vault.items.history']] as [$method, $route]) {
        $this->actingAs($outsider)->withSession(vaultUnlocked())->{$method}(route($route, $live))->assertNotFound();
    }
});

test('a partner who adds an item manages it; moving it needs Edit on the target vault', function () {
    $company = Company::factory()->create();
    $internal = vaultIn($company);
    $client = vaultIn($company, 'Asha Retail');
    $partner = vaultUser(CompanyRole::Partner, $company);
    vaultGrant($partner, $internal, VaultAccessLevel::Edit);
    vaultGrant($partner, $client, VaultAccessLevel::View);

    $this->actingAs($partner)->withSession(vaultUnlocked())->post(route('vault.items.store'), itemPayload($internal))->assertSessionHasNoErrors();
    $item = soleItem($company);

    expect(VaultGrant::query()->withoutGlobalScopes()->where('vault_item_id', $item->id)->sole())
        ->user_id->toBe($partner->id)->level->toBe(VaultAccessLevel::Manage);

    $this->actingAs($partner)->put(route('vault.items.update', $item), itemPayload($client, ['secrets' => []]))->assertForbidden();
});

test('favourites are per user', function () {
    $company = Company::factory()->create();
    $item = vaultItemFor($company);
    $admin = vaultUser(CompanyRole::Admin, $company);

    $this->actingAs($admin)->post(route('vault.items.favourite', $item))->assertRedirect();
    expect(VaultFavourite::query()->withoutGlobalScopes()->where('user_id', $admin->id)->count())->toBe(1);

    $this->actingAs($admin)->post(route('vault.items.favourite', $item));
    expect(VaultFavourite::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('Admins can add a vault', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $partner = vaultUser(CompanyRole::Partner, $company);

    $this->actingAs($admin)->post(route('vault.vaults.store'), ['name' => 'Kiran Exports'])->assertInertiaFlash('toast.message', 'Kiran Exports vault created');
    $this->actingAs($admin)->post(route('vault.vaults.store'), ['name' => 'Kiran Exports'])->assertSessionHasErrors('name');
    $this->actingAs($partner)->post(route('vault.vaults.store'), ['name' => 'Mine'])->assertForbidden();

    expect(Vault::query()->withoutGlobalScopes()->where('company_id', $company->id)->pluck('name')->all())->toBe(['Kiran Exports']);
});

test('a form that fails validation never flashes secrets back into the session', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);

    $response = $this->actingAs($admin)->withSession(vaultUnlocked())
        ->post(route('vault.items.store'), itemPayload(vaultIn($company), ['name' => '']))
        ->assertSessionHasErrors('name');

    expect(json_encode(session()->all()))->not->toContain('demo-GitLab-Pass-2026!')->not->toContain('demo-deploy-token-9')
        ->and(session()->getOldInput('secrets'))->toBeNull();
    $response->assertSessionHasInput('vault_id');
});
