<?php

declare(strict_types=1);

use App\Jobs\QueueHeartbeat;
use App\Models\PlatformAdmin;
use App\Services\Ops\Backup;
use App\Services\Ops\DatabaseDumper;
use App\Services\Ops\Health;
use App\Services\Vault\VaultKeys;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Ops (D95–D97): the encrypted backup, retention, the deep health check and the scheduler.
*/

const BACKUP_PASSWORD = 'a-long-backup-password-for-tests';

beforeEach(function () {
    Storage::fake('backups');
    config(['backup.disk' => 'backups', 'backup.password' => BACKUP_PASSWORD, 'backup.keep_days' => 14]);

    // The real dumper shells out to mysqldump; the test writes a known dump instead.
    app()->instance(DatabaseDumper::class, new class extends DatabaseDumper
    {
        public function dump(string $toFile): void
        {
            file_put_contents($toFile, "-- Coffer test dump\nCREATE TABLE t (id int);\n");
        }
    });
});

function openBackup(string $path, ?string $password): ZipArchive
{
    $local = tempnam(sys_get_temp_dir(), 'cfbk');
    file_put_contents($local, Storage::disk('backups')->get($path));
    $zip = new ZipArchive;
    $zip->open($local);

    if ($password !== null) {
        $zip->setPassword($password);
    }

    return $zip;
}

test('coffer:backup writes an AES-256 encrypted ZIP of the database and storage', function () {
    CarbonImmutable::setTestNow('2026-11-17 01:30:00');
    $root = storage_path('framework/testing/backup-src-'.getmypid());
    @mkdir($root.'/companies/1/expenses', 0777, true);
    file_put_contents($root.'/companies/1/expenses/receipt.pdf', 'PDF');
    config(['backup.folders' => ['private' => $root]]);

    $this->artisan('coffer:backup')->assertSuccessful();

    $path = 'coffer/coffer-2026-11-17-013000.zip';
    Storage::disk('backups')->assertExists($path);

    $locked = openBackup($path, null);
    expect($locked->getFromName('database.sql'))->toBeFalse()
        ->and($locked->statName('database.sql')['encryption_method'])->toBe(ZipArchive::EM_AES_256);

    $zip = openBackup($path, BACKUP_PASSWORD);
    expect($zip->getFromName('database.sql'))->toContain('Coffer test dump')
        ->and($zip->getFromName('storage/private/companies/1/expenses/receipt.pdf'))->toBe('PDF')
        ->and(json_decode((string) $zip->getFromName('manifest.json'), true)['created_at'])->toStartWith('2026-11-17')
        ->and(Cache::get(Backup::HEARTBEAT))->toStartWith('2026-11-17');

    File::deleteDirectory($root);
});

test('coffer:backup refuses to write without a password', function () {
    config(['backup.password' => '']);

    $this->artisan('coffer:backup')->assertFailed();
    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

test('backups past the retention are deleted, never the newest', function () {
    CarbonImmutable::setTestNow('2026-11-17 02:00:00');
    $disk = Storage::disk('backups');
    $disk->put('coffer/coffer-2026-10-01-013000.zip', 'old');
    $disk->put('coffer/coffer-2026-11-10-013000.zip', 'recent');

    $this->artisan('coffer:backup --prune-only')->assertSuccessful();

    $disk->assertMissing('coffer/coffer-2026-10-01-013000.zip');
    $disk->assertExists('coffer/coffer-2026-11-10-013000.zip');

    // Only one, however old: kept.
    CarbonImmutable::setTestNow('2027-06-01 02:00:00');
    $this->artisan('coffer:backup --prune-only')->assertSuccessful();
    $disk->assertExists('coffer/coffer-2026-11-10-013000.zip');
});

test('/health answers 404 without the token, the report with it', function () {
    config(['platform.health_token' => 'health-token-123']);
    Cache::forever(Health::SCHEDULER_HEARTBEAT, now()->toIso8601String());
    (new QueueHeartbeat)->handle();
    Cache::forever(Backup::HEARTBEAT, now()->toIso8601String());

    $this->getJson('/health')->assertNotFound();
    $this->getJson('/health', ['Authorization' => 'Bearer wrong'])->assertNotFound();

    $this->getJson('/health', ['Authorization' => 'Bearer health-token-123'])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.cache.status', 'ok')
        ->assertJsonPath('checks.queue.status', 'ok')
        ->assertJsonPath('checks.scheduler.status', 'ok')
        ->assertJsonPath('checks.vault.status', 'ok')
        ->assertJsonPath('checks.backup.status', 'ok');
});

test('/health is open to a platform admin and reports stale heartbeats', function () {
    CarbonImmutable::setTestNow('2026-11-17 10:00:00');
    Cache::forever(Health::SCHEDULER_HEARTBEAT, '2026-11-17T09:00:00+05:30');

    $admin = PlatformAdmin::query()->create(['name' => 'Priya', 'email' => 'priya@coffer.test', 'password' => 'platform-password']);

    $this->actingAs($admin, 'platform')->getJson('/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.scheduler.status', 'fail')
        ->assertJsonPath('checks.queue.status', 'warn');
});

test('/health fails when the vault master key is not the one that wrapped the data keys', function () {
    $books = singleBooks();
    app(VaultKeys::class)->activeVersion($books->company->id);
    config(['vault.master_key' => 'base64:'.base64_encode(random_bytes(32)), 'vault.previous_master_keys' => '']);
    app()->forgetScopedInstances();

    expect(app(Health::class)->report()['checks']['vault']['status'])->toBe('fail');
});

test('every scheduled task avoids overlaps and runs on one server', function () {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->onOneServer)->toBeTrue(($event->description ?? $event->command).' is not onOneServer');
    }

    $commands = collect($events)->map(fn ($e): string => (string) $e->command)->implode("\n");
    expect($commands)->toContain('coffer:backup')->toContain('coffer:ledger-integrity');
});
