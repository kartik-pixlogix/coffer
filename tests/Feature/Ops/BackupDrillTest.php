<?php

declare(strict_types=1);

use App\Ledger\LedgerGuard;
use App\Services\Ops\BackupDrill;
use App\Services\Ops\PhpMysqlDumper;
use App\Services\Ops\SqlImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| The PHP dumper and the restore drill (Phase 10, D150). The dump runs on its own connection
| (outside the test's transaction), so it sees the migrated schema with no test rows; rows are
| written on that connection too and removed afterwards.
*/

const DRILL_PASSWORD = 'a-long-backup-password-for-tests';

function drillDatabase(): string
{
    return DB::connection()->getDatabaseName().'_drill';
}

/** A second connection to the test database, outside RefreshDatabase's transaction. */
function sideConnection(): Connection
{
    config(['database.connections.drill_side' => DB::connection()->getConfig()]);

    return DB::connection('drill_side');
}

beforeEach(function () {
    Storage::fake('backups');
    config(['backup.disk' => 'backups', 'backup.password' => DRILL_PASSWORD, 'backup.folders' => [], 'backup.dumper' => 'php']);

    sideConnection()->statement('CREATE DATABASE IF NOT EXISTS `'.drillDatabase().'`');
});

afterEach(function () {
    sideConnection()->statement('DROP DATABASE IF EXISTS `'.drillDatabase().'`');
    DB::purge('drill_side');
});

test('the PHP dumper writes tables, rows and the ledger guard triggers, and the importer loads them back', function () {
    $file = tempnam(sys_get_temp_dir(), 'cfdump');
    $side = sideConnection();
    $side->table('password_reset_tokens')->insert(['email' => 'drill@coffer.test', 'token' => "O'Brien \"Quoted\" ; token\nline two", 'created_at' => now()]);

    try {
        $counts = app(PhpMysqlDumper::class)->dump($side, $file);
    } finally {
        $side->table('password_reset_tokens')->where('email', 'drill@coffer.test')->delete();
    }

    $sql = (string) file_get_contents($file);

    expect($sql)->toContain('CREATE TABLE `journal_lines`')
        ->and($sql)->toContain('DELIMITER ;;')
        ->and($sql)->toContain('coffer_journal_lines_no_update')
        ->and($sql)->not->toContain('DEFINER')
        ->and($counts['password_reset_tokens'])->toBe(1)
        ->and($counts)->toHaveKey('journal_lines');

    config(['database.connections.drill_target' => ['database' => drillDatabase()] + DB::connection()->getConfig()]);
    $target = DB::connection('drill_target');
    app(SqlImporter::class)->import($target, $file);

    expect($target->table('password_reset_tokens')->where('email', 'drill@coffer.test')->value('token'))->toBe("O'Brien \"Quoted\" ; token\nline two")
        ->and(LedgerGuard::installed($target))->toBeTrue();

    $target->getSchemaBuilder()->dropAllTables();
    DB::purge('drill_target');
    @unlink($file);
});

test('coffer:backup records row counts, and the drill restores the backup and checks them', function () {
    CarbonImmutable::setTestNow('2026-11-17 01:30:00');
    config(['backup.drill_database' => drillDatabase()]);

    $this->artisan('coffer:backup --no-files')->assertSuccessful();
    $this->artisan('coffer:backup-drill')
        ->expectsOutputToContain('tables restored with the row counts recorded at backup time')
        ->expectsOutputToContain('the restored books balance')
        ->expectsOutputToContain('the ledger guard triggers came back')
        ->assertSuccessful();

    expect(Cache::get(BackupDrill::HEARTBEAT))->toStartWith('2026-11-17');
});

test('without a drill database the drill still proves the backup decrypts', function () {
    $this->artisan('coffer:backup --no-files')->assertSuccessful();

    $this->artisan('coffer:backup-drill')
        ->expectsOutputToContain('decrypt and read')
        ->expectsOutputToContain('Not restored')
        ->assertSuccessful();
});

test('the drill fails on a wrong password, and never restores into the live database', function () {
    $this->artisan('coffer:backup --no-files')->assertSuccessful();

    config(['backup.password' => 'the-wrong-password-entirely']);
    $this->artisan('coffer:backup-drill')->expectsOutputToContain('wrong BACKUP_PASSWORD')->assertFailed();

    config(['backup.password' => DRILL_PASSWORD, 'backup.drill_database' => DB::connection()->getDatabaseName()]);
    $this->artisan('coffer:backup-drill')->expectsOutputToContain('is the live database')->assertFailed();
});

test('the drill fails when there is no backup yet', function () {
    $this->artisan('coffer:backup-drill')->expectsOutputToContain('no backup to drill')->assertFailed();
});

test('the drill leaves no work folder behind, even when it fails', function () {
    $this->artisan('coffer:backup --no-files')->assertSuccessful();
    config(['backup.password' => 'the-wrong-password-entirely']);

    $this->artisan('coffer:backup-drill')->assertFailed();
    config(['backup.password' => DRILL_PASSWORD]);
    $this->artisan('coffer:backup-drill')->assertSuccessful();

    expect(glob(storage_path('framework/drill-*')) ?: [])->toBe([]);
});

test('the drill refuses a database that holds tables it didn’t create', function () {
    config(['backup.drill_database' => drillDatabase()]);
    config(['database.connections.drill_target' => ['database' => drillDatabase()] + DB::connection()->getConfig()]);
    DB::connection('drill_target')->getSchemaBuilder()->create('someone_elses_table', fn ($t) => $t->id());

    $this->artisan('coffer:backup --no-files')->assertSuccessful();
    $this->artisan('coffer:backup-drill')->expectsOutputToContain('already holds tables')->assertFailed();

    expect(DB::connection('drill_target')->getSchemaBuilder()->hasTable('someone_elses_table'))->toBeTrue();
    DB::purge('drill_target');
});

test('the drill runs again on the database it marked as its own', function () {
    config(['backup.drill_database' => drillDatabase()]);

    $this->artisan('coffer:backup --no-files')->assertSuccessful();
    $this->artisan('coffer:backup-drill')->assertSuccessful();
    $this->artisan('coffer:backup-drill')->assertSuccessful();
});
