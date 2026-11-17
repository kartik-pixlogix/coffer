<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Jobs\CommitVaultImport;
use App\Models\Company;
use App\Models\VaultAuditEvent;
use App\Models\VaultImport;
use App\Models\VaultItem;
use App\Notifications\Events\ImportFinished;
use App\Services\Vault\ItemSecrets;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function importUpload(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path("tests/Fixtures/vault-import/{$name}")));
}

test('a Chrome CSV goes upload → preview → import; the file is sealed, then deleted; duplicates are skipped', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = vaultIn($company, 'Internal');
    // Already in the vault: same name, username and password.
    vaultItemFor($company, ['password' => 'fake-Chrome-Pass-1!'], [
        'name' => 'dash.cloudflare.com', 'provider' => null, 'fields' => ['url' => 'https://dash.cloudflare.com/login', 'username' => 'dns@example.test'],
    ], $vault);

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->post(route('vault.import.store'), ['file' => importUpload('chrome.csv'), 'vault_id' => $vault->id])
        ->assertRedirect();

    $import = VaultImport::query()->withoutGlobalScopes()->sole();
    expect($import->format->value)->toBe('chrome_csv')->and($import->rows)->toBe(2)->and($import->path)->not->toBeNull();

    // Sealed on disk: no plaintext.
    $stored = (string) Storage::disk('local')->get((string) $import->path);
    expect($stored)->not->toContain('fake-Chrome-Pass')->not->toContain('dns@example.test');

    $preview = $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.import', ['import' => $import->id]));
    $preview->assertInertia(fn (Assert $page) => $page->component('vault/import')
        ->where('step', 'preview')
        ->where('preview.counts', ['new' => 1, 'duplicate' => 1, 'invalid' => 0, 'skipped' => 0])
        ->where('preview.rows.0.status', 'duplicate')
        ->where('preview.rows.1.name', 'example.test')
        ->where('preview.rows.1.secrets', ['Password', 'Notes']));
    expect((string) $preview->getContent())->not->toContain('fake-Chrome-Pass');

    Notification::fake();
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.commit', $import))->assertRedirect();
    Notification::assertSentTo($admin, ImportFinished::class, fn (ImportFinished $n, array $c, object $u) => $n->toArray($u)['body'] === '1 item added to Internal from chrome.csv, 1 skipped.');

    $import->refresh();
    expect($import->status)->toBe('done')
        ->and($import->path)->toBeNull()
        ->and($import->summary['created'])->toBe(1)
        ->and($import->summary['duplicates'])->toBe([['line' => 2, 'name' => 'dash.cloudflare.com']])
        ->and(Storage::disk('local')->allFiles('vault-imports'))->toBe([]);

    $item = VaultItem::query()->withoutGlobalScopes()->where('name', 'example.test')->sole();
    expect(app(CurrentCompany::class)->run($company, fn () => app(ItemSecrets::class)->get($item, 'password')))->toBe('fake-Chrome-Pass-2!')
        ->and($item->strength)->not->toBeNull();

    $audit = VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'imported')->sole();
    expect($audit->meta)->toEqual(['format' => 'chrome_csv', 'created' => 1, 'duplicates' => 1, 'skipped' => 0]);
});

test('a plain CSV is mapped first; the commit is queued with the id only', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = vaultIn($company);

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => importUpload('generic.csv'), 'vault_id' => $vault->id]);
    $import = VaultImport::query()->withoutGlobalScopes()->sole();

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.import'))
        ->assertInertia(fn (Assert $page) => $page->where('step', 'map')->where('import.mapping', ['name', 'username', 'password', 'notes', 'tags']));

    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.import.mapping', $import), ['mapping' => [null, 'username', 'password', 'password', null]])
        ->assertSessionHasErrors('mapping');
    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.import.mapping', $import), ['mapping' => ['name', 'username', 'password', 'notes', null]])
        ->assertRedirect(route('vault.import', ['import' => $import->id, 'preview' => 1]));

    Queue::fake();
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.commit', $import))->assertRedirect();
    Queue::assertPushed(CommitVaultImport::class, function (CommitVaultImport $job) use ($import) {
        expect(serialize($job))->not->toContain('fake-Generic-Pass');

        return $job->importId === $import->id;
    });
    expect($import->refresh()->status)->toBe('queued');
});

test('a Bitwarden JSON import keeps 2FA, notes, custom fields and tags; cancel deletes the upload', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = vaultIn($company);

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => importUpload('bitwarden.json'), 'vault_id' => $vault->id]);
    $import = VaultImport::query()->withoutGlobalScopes()->sole();
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.commit', $import));

    $github = VaultItem::query()->withoutGlobalScopes()->where('name', 'GitHub')->sole();
    expect($github->has_totp)->toBeTrue()->and($github->tags)->toBe(['saarthi'])->and($github->provider)->toBe('github')
        ->and(collect($github->custom_fields)->pluck('label')->all())->toBe(['Recovery email', 'PAT'])
        ->and($import->refresh()->summary['skipped'])->toBe([['line' => 3, 'reason' => 'Cards aren’t imported']]);

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => importUpload('lastpass.csv'), 'vault_id' => $vault->id]);
    $second = VaultImport::query()->withoutGlobalScopes()->latest('id')->first();
    $this->actingAs($admin)->withSession(vaultUnlocked())->delete(route('vault.import.destroy', $second))->assertRedirect(route('vault.import'));
    expect($second->refresh()->status)->toBe('cancelled')->and(Storage::disk('local')->allFiles('vault-imports'))->toBe([]);
});

test('imports need Create items with Edit on the vault, the lock open, and your own import', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    $viewer = vaultUser(CompanyRole::Viewer, $company);
    $vault = vaultIn($company);
    vaultGrant($member, $vault, VaultAccessLevel::Use);

    $this->actingAs($member)->withSession(vaultUnlocked())->get(route('vault.import'))->assertForbidden();
    $this->actingAs($viewer)->get(route('vault.import'))->assertForbidden();

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => importUpload('chrome.csv'), 'vault_id' => 999999])
        ->assertSessionHasErrors('vault_id');
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['vault_id' => $vault->id])
        ->assertSessionHasErrors('file');
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => UploadedFile::fake()->createWithContent('x.csv', "name\n"), 'vault_id' => $vault->id])
        ->assertSessionHasErrors('file');

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => importUpload('chrome.csv'), 'vault_id' => $vault->id]);
    $import = VaultImport::query()->withoutGlobalScopes()->sole();

    $other = vaultUser(CompanyRole::Admin, $company);
    $this->actingAs($other)->withSession(vaultUnlocked())->post(route('vault.import.commit', $import))->assertNotFound();

    $elsewhere = vaultUser(CompanyRole::Admin);
    $this->actingAs($elsewhere)->withSession(vaultUnlocked())->post(route('vault.import.commit', $import))->assertNotFound();

    $this->flushSession();
    $this->actingAs($admin)->post(route('vault.import.commit', $import))->assertRedirect();
    expect($import->refresh()->status)->toBe('uploaded');
});

test('uploads left unfinished for a day are deleted', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = vaultIn($company);
    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.import.store'), ['file' => importUpload('chrome.csv'), 'vault_id' => $vault->id]);

    $this->artisan('coffer:vault-imports-clean')->expectsOutput('Deleted 0 unfinished vault uploads.');

    CarbonImmutable::setTestNow('2026-11-16 10:30:00');
    $this->artisan('coffer:vault-imports-clean')->expectsOutput('Deleted 1 unfinished vault upload.');
    expect(Storage::disk('local')->allFiles('vault-imports'))->toBe([])
        ->and(VaultImport::query()->withoutGlobalScopes()->sole()->status)->toBe('cancelled');
});
