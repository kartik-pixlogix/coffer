<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultAuditAction;
use App\Models\Company;
use App\Models\VaultAuditEvent;
use App\Services\Vault\ItemAttachments;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function auditActions(Company $company): array
{
    return VaultAuditEvent::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')
        ->get()->map(fn (VaultAuditEvent $e) => $e->action->value.($e->field ? ':'.$e->field : ''))->all();
}

function currentCode(string $key = VAULT_TEST_2FA): string
{
    return (new Google2FA)->getCurrentOtp($key);
}

test('reveal returns one field to an unlocked, verified user with Use, and audits it', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!', 'notes' => 'Secret note']);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertExactJson(['field' => 'password', 'label' => 'Password', 'value' => 'demo-Reveal-Me-42!', 'hideAfter' => 30, 'clearAfter' => 30]);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.copy', $item), ['field' => 'notes'])
        ->assertOk()->assertJsonPath('value', 'Secret note');

    expect(auditActions($company))->toContain('revealed:password', 'copied:notes');

    $event = VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'revealed')->sole();
    expect($event->user_id)->toBe($admin->id)
        ->and($event->vault_item_id)->toBe($item->id)
        ->and($event->ip)->toBe('127.0.0.1')
        ->and($event->meta)->toBe(['label' => 'Password']);
});

test('reveal is refused while locked (423), without a fresh 2FA check (428) and to View-only users (403)', function () {
    $company = Company::factory()->create();
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $partner = vaultUser(CompanyRole::Partner, $company);
    vaultGrant($partner, $item->vault, VaultAccessLevel::View);

    $this->actingAs($admin)->postJson(route('vault.items.reveal', $item), ['field' => 'password'])
        ->assertStatus(423)->assertJsonPath('locked', true)->assertJsonMissing(['value' => 'demo-Reveal-Me-42!']);

    $this->actingAs($admin)->withSession(vaultUnlocked(verified: false))
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])
        ->assertStatus(428)->assertJsonPath('stepUp', true);

    // The step-up window: a code older than 15 minutes asks again.
    $this->actingAs($admin)->withSession(['vault.unlocked_at' => now()->getTimestamp(), 'vault.active_at' => now()->getTimestamp(), 'vault.verified_at' => now()->subMinutes(16)->getTimestamp()])
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertStatus(428);

    $this->actingAs($partner)->withSession(vaultUnlocked())
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertForbidden();

    expect(auditActions($company))->not->toContain('revealed:password');
});

test('the vault locks itself after 15 minutes idle', function () {
    $company = Company::factory()->create();
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $session = vaultUnlocked();

    CarbonImmutable::setTestNow('2026-11-15 10:16:00');

    $this->actingAs($admin)->withSession($session)
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertStatus(423);
});

test('only the item’s secret fields can be revealed, and empty ones say so', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);

    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), ['field' => 'username'])->assertStatus(422);
    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), ['field' => 'recovery_codes'])->assertNotFound();
    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), [])->assertJsonValidationErrors('field');
});

test('the one-time code is computed on the server; the setup key never leaves', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!', 'totp' => 'jbsw y3dp ehpk 3pxp']);

    $response = $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.totp', $item))->assertOk();

    $expected = (new Google2FA)->oathTotp('JBSWY3DPEHPK3PXP', intdiv(now()->getTimestamp(), 30));

    expect($response->json('code'))->toBe($expected)
        ->and($response->json('secondsLeft'))->toBe(30)
        ->and($response->getContent())->not->toContain('JBSWY3DPEHPK3PXP')
        ->and(auditActions($company))->toContain('used_code:totp');

    $plain = vaultItemFor($company, ['password' => 'x-long-password-1']);
    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.totp', $plain))->assertNotFound();
});

test('reveals, copies and codes are rate-limited to 30 a minute per user', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertOk();
    }

    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.copy', $item), ['field' => 'password'])->assertStatus(429);
    expect(collect(auditActions($company))->filter(fn ($a) => $a === 'revealed:password'))->toHaveCount(30);
});

test('another company’s item is a 404 for every secret endpoint', function () {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $mine);
    $item = vaultItemFor($theirs, ['password' => 'demo-Other-Company-1', 'totp' => VAULT_TEST_2FA]);

    foreach (['vault.items.reveal', 'vault.items.copy', 'vault.items.totp'] as $route) {
        $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route($route, $item), ['field' => 'password'])->assertNotFound();
    }
});

test('attachments are stored encrypted and download decrypted after the same checks, audited', function () {
    Storage::fake('local');
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);
    $attachment = app(CurrentCompany::class)->run($company, fn () => app(ItemAttachments::class)
        ->store($item, UploadedFile::fake()->createWithContent('recovery.txt', 'DEMO-RECOVERY-CODES-123'), $admin));

    $onDisk = Storage::disk('local')->get($attachment->path);
    expect($onDisk)->not->toContain('DEMO-RECOVERY-CODES-123');

    $this->actingAs($admin)->withSession(vaultUnlocked(verified: false))->get(route('vault.attachments.show', $attachment))->assertStatus(428);

    $response = $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.attachments.show', $attachment))->assertOk();
    expect($response->getContent())->toBe('DEMO-RECOVERY-CODES-123')
        ->and($response->headers->get('Content-Disposition'))->toContain('recovery.txt')
        ->and(auditActions($company))->toContain('downloaded');
});

test('unlock with a 2FA code verifies; a password only unlocks; mistakes count down to a 5-minute pause', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);

    $this->actingAs($admin)->postJson(route('vault.unlock'), ['password' => 'password'])
        ->assertOk()->assertJsonPath('state.unlocked', true)->assertJsonPath('state.verifiedUntil', null);

    $this->actingAs($admin)->postJson(route('vault.unlock'), ['code' => currentCode()])
        ->assertOk()->assertJsonPath('state.verifiedUntil', now()->addMinutes(15)->toIso8601String());

    $this->actingAs($admin)->postJson(route('vault.unlock'), ['code' => 'VAULT-RECOVERY-1'])->assertOk();
    expect($admin->fresh()->recoveryCodes())->not->toContain('VAULT-RECOVERY-1');

    $this->actingAs($admin)->postJson(route('vault.unlock'), ['code' => '000000'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'That code didn’t match. 4 tries left before a 5-minute pause.');

    for ($i = 0; $i < 4; $i++) {
        $this->actingAs($admin)->postJson(route('vault.unlock'), ['password' => 'wrong']);
    }

    $this->actingAs($admin)->postJson(route('vault.unlock'), ['code' => currentCode()])->assertStatus(429);

    expect(collect(auditActions($company))->countBy()->all())->toMatchArray(['unlocked' => 3, 'failed_unlock' => 5]);
});

test('lock ends the session’s access; touch keeps it alive', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);

    $this->actingAs($admin)->withSession(vaultUnlocked());
    CarbonImmutable::setTestNow('2026-11-15 10:10:00');
    $this->postJson(route('vault.touch'))->assertOk()->assertJsonPath('state.lockAt', CarbonImmutable::parse('2026-11-15 10:25:00')->toIso8601String());

    $this->postJson(route('vault.lock'))->assertOk()->assertJsonPath('state.unlocked', false);
    $this->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertStatus(423);

    expect(auditActions($company))->toContain(VaultAuditAction::Locked->value);
});

test('users without 2FA can’t unlock or reveal', function () {
    $company = Company::factory()->create();
    $admin = userWithRole(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);

    $this->actingAs($admin)->postJson(route('vault.unlock'), ['password' => 'password'])->assertForbidden();
    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertForbidden();
});

test('viewers have no vault at all', function () {
    $company = Company::factory()->create();
    $viewer = vaultUser(CompanyRole::Viewer, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Reveal-Me-42!']);

    $this->actingAs($viewer)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertForbidden();
    $this->actingAs($viewer)->postJson(route('vault.unlock'), ['code' => currentCode()])->assertForbidden();
});

test('the reuse check compares fingerprints with items the user can use', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $netlify = vaultItemFor($company, ['password' => 'Kiran@2025'], ['name' => 'Netlify — marketing site']);
    vaultItemFor($company, ['password' => 'Something-else-entirely-9'], ['name' => 'Other']);

    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.reuse'), ['password' => 'Kiran@2025'])
        ->assertOk()->assertJsonPath('matches', [['id' => $netlify->id, 'name' => 'Netlify — marketing site']])->assertJsonPath('checked', 2);

    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.reuse'), ['password' => 'Kiran@2025', 'item_id' => $netlify->id])
        ->assertOk()->assertJsonPath('matches', []);

    $this->flushSession();
    $this->actingAs($admin)->postJson(route('vault.reuse'), ['password' => 'Kiran@2025'])->assertStatus(423);
});
