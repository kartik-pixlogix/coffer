<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Models\Company;
use App\Models\VaultAuditEvent;
use App\Models\VaultImport;
use App\Models\VaultItem;
use App\Models\VaultPolicySetting;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\Transfer\ExportCrypto;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

const EXPORT_PASS = 'correct horse battery staple';

function exportBooks(): object
{
    $company = Company::factory()->create(['name' => 'Saarthi Portal JV']);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = vaultIn($company, 'Internal');
    $github = vaultItemFor($company, ['password' => 'demo-Export-GH-1!', 'totp' => 'JBSWY3DPEHPK3PXP', 'notes' => 'demo export note'], [
        'name' => 'GitHub — vertexlabs org', 'tags' => ['code'], 'expires_at' => '2027-01-31',
        'custom_fields' => [['label' => 'PAT', 'secret' => true, 'value' => 'demo-pat-xyz'], ['label' => 'Org', 'secret' => false, 'value' => 'vertexlabs']],
    ], $vault);
    $aws = vaultItemFor($company, ['password' => 'demo-Export-AWS-2!'], ['name' => 'AWS — root account', 'restricted' => true], $vault);

    return (object) compact('company', 'admin', 'vault', 'github', 'aws');
}

test('the export is an encrypted envelope, audited, that imports back into the vault', function () {
    $b = exportBooks();

    $this->actingAs($b->admin)->withSession(vaultUnlocked())->get(route('vault.export'))
        ->assertInertia(fn (Assert $page) => $page->component('vault/export')->where('needsCode', false)->where('requireReason', true)->where('vaults.0.items', 2));

    $response = $this->actingAs($b->admin)->withSession(vaultUnlocked())->postJson(route('vault.export.store'), [
        'passphrase' => EXPORT_PASS, 'passphrase_confirmation' => EXPORT_PASS, 'reason' => 'Moving to a new laptop',
    ])->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="coffer-vault-saarthi-portal-jv-2026-11-15.coffer.json"');

    $file = (string) $response->getContent();
    expect($file)->not->toContain('demo-Export')->not->toContain('demo-pat-xyz')->not->toContain('GitHub')
        ->and(json_decode($file, true)['items'])->toBe(2);

    $plain = json_decode((new ExportCrypto)->open($file, EXPORT_PASS), true);
    expect($plain['items'][1]['name'])->toBe('GitHub — vertexlabs org')
        ->and($plain['items'][1]['secrets'])->toMatchArray(['password' => 'demo-Export-GH-1!', 'totp' => 'JBSWY3DPEHPK3PXP', 'notes' => 'demo export note'])
        ->and($plain['items'][1]['custom_fields'][0])->toBe(['label' => 'PAT', 'secret' => true, 'value' => 'demo-pat-xyz']);

    $event = VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'exported')->sole();
    expect($event->meta)->toEqual(['what' => 'vault export', 'items' => 2, 'vaults' => 'all', 'reason' => 'Moving to a new laptop', 'file' => 'coffer-vault-saarthi-portal-jv-2026-11-15.coffer.json']);

    // Round trip: into another company's vault, with the passphrase.
    $other = Company::factory()->create();
    $otherAdmin = vaultUser(CompanyRole::Admin, $other);
    $target = vaultIn($other, 'Restored');
    $upload = UploadedFile::fake()->createWithContent('export.coffer.json', $file);

    $this->actingAs($otherAdmin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => $upload, 'vault_id' => $target->id])
        ->assertSessionHasErrors(['passphrase' => 'This is a Coffer export. Enter its passphrase to import it.']);
    $this->actingAs($otherAdmin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => UploadedFile::fake()->createWithContent('export.coffer.json', $file), 'vault_id' => $target->id, 'passphrase' => 'not the passphrase'])
        ->assertSessionHasErrors('passphrase');
    $this->actingAs($otherAdmin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => UploadedFile::fake()->createWithContent('export.coffer.json', $file), 'vault_id' => $target->id, 'passphrase' => EXPORT_PASS])
        ->assertRedirect();

    $import = VaultImport::query()->withoutGlobalScopes()->sole();
    expect($import->format->value)->toBe('coffer');
    $this->actingAs($otherAdmin)->withSession(vaultUnlocked())->post(route('vault.import.commit', $import))->assertRedirect();
    expect($import->refresh()->summary['created'])->toBe(2);

    $copy = VaultItem::query()->withoutGlobalScopes()->where('company_id', $other->id)->where('name', 'GitHub — vertexlabs org')->sole();
    $secrets = app(CurrentCompany::class)->run($other, fn () => app(ItemSecrets::class)->all($copy));
    expect($secrets)->toMatchArray(['password' => 'demo-Export-GH-1!', 'totp' => 'JBSWY3DPEHPK3PXP', 'notes' => 'demo export note'])
        ->and($copy->has_totp)->toBeTrue()
        ->and($copy->tags)->toBe(['code'])
        ->and($copy->expires_at?->toDateString())->toBe('2027-01-31')
        ->and(collect($copy->custom_fields)->pluck('label')->all())->toBe(['PAT', 'Org'])
        ->and(VaultItem::query()->withoutGlobalScopes()->where('company_id', $other->id)->where('name', 'AWS — root account')->sole()->restricted)->toBeTrue();

    // Importing it again finds them all as duplicates.
    $this->actingAs($otherAdmin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => UploadedFile::fake()->createWithContent('export.coffer.json', $file), 'vault_id' => $target->id, 'passphrase' => EXPORT_PASS]);
    $again = VaultImport::query()->withoutGlobalScopes()->latest('id')->first();
    $this->actingAs($otherAdmin)->withSession(vaultUnlocked())->get(route('vault.import', ['import' => $again->id]))
        ->assertInertia(fn (Assert $page) => $page->where('preview.counts.duplicate', 2)->where('preview.counts.new', 0));
});

test('export needs a fresh 2FA code, a reason by policy, a strong confirmed passphrase', function () {
    $b = exportBooks();
    $old = now()->subMinutes(20)->getTimestamp();
    $stale = ['vault.unlocked_at' => now()->getTimestamp(), 'vault.active_at' => now()->getTimestamp(), 'vault.verified_at' => $old];

    $this->actingAs($b->admin)->withSession($stale)->get(route('vault.export'))->assertInertia(fn (Assert $page) => $page->where('needsCode', true));

    $this->actingAs($b->admin)->withSession($stale)->postJson(route('vault.export.store'), ['passphrase' => EXPORT_PASS, 'passphrase_confirmation' => EXPORT_PASS, 'reason' => 'x', 'code' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->actingAs($b->admin)->withSession($stale)->postJson(route('vault.export.store'), ['passphrase' => 'short', 'passphrase_confirmation' => 'other', 'reason' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['passphrase', 'reason']);

    $this->actingAs($b->admin)->withSession($stale)->postJson(route('vault.export.store'), [
        'passphrase' => EXPORT_PASS, 'passphrase_confirmation' => EXPORT_PASS, 'reason' => 'Audit', 'code' => (new Google2FA)->getCurrentOtp(VAULT_TEST_2FA),
    ])->assertOk();

    // Locked: 423.
    $this->flushSession();
    $this->actingAs($b->admin)->postJson(route('vault.export.store'), ['passphrase' => EXPORT_PASS, 'passphrase_confirmation' => EXPORT_PASS, 'reason' => 'x'])->assertStatus(423);

    // The passphrase never reaches the session, the logs or the audit row.
    expect(DB::table('vault_audit_events')->pluck('meta')->implode(' '))->not->toContain(EXPORT_PASS)
        ->and(DB::table('activity_log')->pluck('properties')->implode(' '))->not->toContain(EXPORT_PASS);
});

test('who can export follows the policy; partners export only what they can use', function () {
    $b = exportBooks();
    $partner = vaultUser(CompanyRole::Partner, $b->company);
    vaultGrant($partner, $b->github, VaultAccessLevel::Use);
    $member = vaultUser(CompanyRole::Member, $b->company);

    $this->actingAs($partner)->withSession(vaultUnlocked())->get(route('vault.export'))->assertForbidden();

    VaultPolicySetting::query()->forceCreate(['company_id' => $b->company->id, 'reauth_minutes' => 15, 'fresh_code_each_reveal' => false, 'idle_lock_minutes' => 15, 'lock_on_close' => false,
        'clipboard_clear_seconds' => 30, 'reveal_seconds' => 30, 'export_who' => 'admins_partners', 'export_reason' => false, 'breach_checks' => true, 'generator_min_length' => 12]);

    $file = (string) $this->actingAs($partner)->withSession(vaultUnlocked())
        ->postJson(route('vault.export.store'), ['passphrase' => EXPORT_PASS, 'passphrase_confirmation' => EXPORT_PASS])->assertOk()->getContent();
    expect(json_decode($file, true)['items'])->toBe(1);
    $this->actingAs($member)->withSession(vaultUnlocked())->get(route('vault.export'))->assertForbidden();

    VaultPolicySetting::query()->withoutGlobalScopes()->update(['export_who' => 'none']);
    $this->actingAs($b->admin)->withSession(vaultUnlocked())->get(route('vault.export'))->assertForbidden();
});
