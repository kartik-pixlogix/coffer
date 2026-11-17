<?php

declare(strict_types=1);

use App\Jobs\CheckVaultBreaches;
use App\Jobs\CommitVaultImport;
use App\Jobs\ReencryptVaultData;
use App\Models\Company;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Models\VaultAuditEvent;
use App\Models\VaultImport;
use App\Models\VaultItem;
use App\Models\VaultSecret;
use App\Models\Workspace;
use Database\Seeders\DemoVaultSeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spec §14.3 "Never leak secrets" and the Phase 7 acceptance test: with the demo vault
 * seeded, no plaintext secret appears in any page, drawer, edit form, JSON list, shared
 * prop, log line, activity-log diff, audit row, metadata column or queue payload.
 */
function assertNoSecretIn(string $haystack, string $where): void
{
    foreach (DemoVaultSeeder::SECRETS as $secret) {
        foreach (array_unique([$secret, trim((string) json_encode($secret), '"'), htmlspecialchars($secret, ENT_QUOTES), htmlspecialchars(trim((string) json_encode($secret), '"'), ENT_QUOTES)]) as $form) {
            expect(str_contains($haystack, $form))->toBeFalse("A demo secret appeared in {$where}.");
        }
    }
}

function assertBodyClean(TestResponse $response, string $where): void
{
    expect($response->getStatusCode())->toBeLessThan(500, "{$where} failed");
    $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
    assertNoSecretIn($body, $where);
}

test('no seeded secret appears in pages, props, logs, activity diffs, audit rows or queue payloads', function () {
    $log = storage_path('logs/vault-leak-test.log');
    @unlink($log);
    config(['logging.default' => 'leak', 'logging.channels.leak' => ['driver' => 'single', 'path' => $log, 'level' => 'debug']]);

    $this->seed([DemoWorkspaceSeeder::class, DemoVaultSeeder::class]);
    $company = Company::query()->where('name', 'Saarthi Portal JV')->sole();
    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $rohan = User::query()->where('email', 'rohan@vertexlabs.in')->sole();
    $items = VaultItem::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
    $member = Member::query()->withoutGlobalScopes()->where('user_id', $rohan->id)->sole();
    expect($items)->toHaveCount(13);

    foreach ([$aarav, $rohan] as $user) {
        $this->actingAs($user)->post(route('companies.switch', $company));

        // Locked first, then unlocked and verified (the most a session can see).
        assertBodyClean($this->get(route('vault.index')), 'the locked vault page');
        $this->withSession(vaultUnlocked());

        assertBodyClean($this->get(route('vault.index')), 'the vault page');
        assertBodyClean($this->get(route('dashboard')), 'the dashboard');
        assertBodyClean($this->get(route('team.show', $member)), 'the member profile');
        assertBodyClean($this->get(route('vault.requests')), 'the requests inbox');
        assertBodyClean($this->get(route('vault.health')), 'the health page');
        assertBodyClean($this->get(route('vault.health.export')), 'the health report CSV');
        assertBodyClean($this->get(route('vault.audit', ['range' => '30d'])), 'the audit log');
        assertBodyClean($this->get(route('vault.policies')), 'the policies page');
        assertBodyClean($this->get(route('vault.audit.export', ['range' => '30d'])), 'the audit CSV');

        foreach ($items as $item) {
            assertBodyClean($this->get(route('vault.index', ['item' => $item->id])), "the drawer of {$item->name}");
            assertBodyClean($this->getJson(route('vault.items.history', $item)), "the history of {$item->name}");
            assertBodyClean($this->get(route('vault.items.edit', $item)), "the edit form of {$item->name}");
            assertBodyClean($this->getJson(route('vault.items.share', $item)), "the share drawer of {$item->name}");
        }
    }

    $this->actingAs($aarav)->withSession(vaultUnlocked());
    assertBodyClean($this->get(route('vault.items.create')), 'the catalog');
    assertBodyClean($this->get(route('vault.items.create', ['provider' => 'github'])), 'the add form');

    // Sharing writes audit rows and notifications: names and levels only.
    $github = $items->firstWhere('name', 'GitHub — vertexlabs org');
    $this->postJson(route('vault.items.grants.store', $github), ['user_ids' => [$rohan->id], 'level' => 'use', 'ends' => '7d'])->assertOk();
    $this->actingAs($rohan)->withSession(vaultUnlocked())
        ->post(route('vault.requests.store'), ['item_id' => $github->id, 'level' => 'edit', 'duration' => '24h', 'reason' => 'Rotating the deploy key.'])->assertRedirect();
    $this->actingAs($aarav)->withSession(vaultUnlocked());
    assertBodyClean($this->get(route('vault.requests')), 'the requests inbox with a request');

    // Part B pages and files: policies, import, export (the file is encrypted end to end).
    assertBodyClean($this->get(route('vault.import')), 'the import page');
    assertBodyClean($this->get(route('vault.export')), 'the export page');
    $export = $this->postJson(route('vault.export.store'), ['passphrase' => 'leak test passphrase', 'passphrase_confirmation' => 'leak test passphrase', 'reason' => 'Leak test']);
    assertBodyClean($export, 'the encrypted export file');
    $this->withSession(vaultUnlocked());

    // Importing the export back: the sealed upload, the preview and the job payload stay clean.
    // (13 more items would pass the demo's Growth trial limit of 25, so this runs on Business, D82.)
    Workspace::query()->update(['plan_id' => Plan::query()->where('key', 'business')->value('id')]);
    Queue::fake();
    $this->post(route('vault.import.store'), [
        'file' => UploadedFile::fake()->createWithContent('export.coffer.json', (string) $export->getContent()),
        'vault_id' => $items->first()->vault_id,
        'passphrase' => 'leak test passphrase',
    ])->assertRedirect();
    $import = VaultImport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    assertNoSecretIn((string) Storage::disk(VaultImport::DISK)->get((string) $import->path), 'the sealed import upload');
    assertBodyClean($this->get(route('vault.import', ['import' => $import->id])), 'the import preview');
    $this->post(route('vault.import.commit', $import))->assertRedirect();
    Queue::assertPushed(CommitVaultImport::class, function (CommitVaultImport $job) {
        assertNoSecretIn(serialize($job), 'the import job payload');

        return true;
    });
    $this->artisan('coffer:vault-breach-check')->assertSuccessful();
    config(['vault.hibp_enabled' => true]);
    $this->post(route('vault.health.check'))->assertRedirect();
    Queue::assertPushed(CheckVaultBreaches::class, function (CheckVaultBreaches $job) {
        assertNoSecretIn(serialize($job), 'the breach check job payload');

        return true;
    });
    config(['vault.hibp_enabled' => false]);
    $this->delete(route('vault.import.destroy', $import))->assertRedirect();

    // A ciphertext that no longer opens: the error is logged, without any secret.
    $row = VaultSecret::query()->withoutGlobalScopes()->where('vault_item_id', $items->firstWhere('name', 'MySQL — production database')->id)->where('field', 'password')->sole();
    DB::table('vault_secrets')->where('id', $row->id)->update(['ciphertext' => base64_encode(str_repeat("\0", 40))]);
    $this->postJson(route('vault.items.reveal', $row->vault_item_id), ['field' => 'password'])->assertServerError();

    // Rotation queues ids only.
    Queue::fake();
    $this->artisan('coffer:vault-rotate', ['--company' => [$company->id]])->assertSuccessful();
    Queue::assertPushed(ReencryptVaultData::class, function (ReencryptVaultData $job) {
        assertNoSecretIn(serialize($job), 'the rotation job payload');

        return true;
    });

    assertNoSecretIn(is_file($log) ? (string) file_get_contents($log) : '', 'the log file');
    expect(is_file($log) ? (string) file_get_contents($log) : '')->toContain('couldn’t be decrypted');
    assertNoSecretIn(DB::table('activity_log')->pluck('properties')->implode("\n"), 'activity_log properties');
    assertNoSecretIn(json_encode(VaultAuditEvent::query()->withoutGlobalScopes()->get()->toArray()) ?: '', 'the vault audit log');
    assertNoSecretIn(json_encode(DB::table('vault_items')->get()) ?: '', 'vault item metadata');
    assertNoSecretIn(json_encode(DB::table('vault_secrets')->get()) ?: '', 'stored ciphertext');
    assertNoSecretIn(json_encode(DB::table('vault_item_versions')->get()) ?: '', 'version snapshots');

    foreach (['vault_imports', 'vault_access_requests', 'vault_offboardings', 'vault_policies', 'vault_grants', 'jobs'] as $table) {
        assertNoSecretIn(json_encode(DB::table($table)->get()) ?: '', "the {$table} table");
    }
    expect(DB::table('notifications')->count())->toBeGreaterThan(0);
    assertNoSecretIn(DB::table('notifications')->pluck('data')->implode(PHP_EOL), 'stored notifications');

    @unlink($log);
});
