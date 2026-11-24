<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Ledger\LedgerGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Proves a backup restores (D150). Always: the archive opens with BACKUP_PASSWORD, its
 * manifest reads, and the dump is there and starts like a dump. With BACKUP_DRILL_DATABASE
 * (a scratch database on the same server, never the live one): the dump is loaded into it
 * and every table's row count must equal the count the manifest recorded at backup time,
 * the journal's debits must equal its credits, and the ledger guard triggers must be back.
 */
class BackupDrill
{
    public const string HEARTBEAT = 'ops:backup:drill';

    /** The table that marks a database as the drill's scratch database. */
    private const string MARKER = 'coffer_backup_drill';

    public function __construct(private readonly SqlImporter $importer) {}

    /** @return array{path: string, taken: string, restored: bool, checks: list<string>} */
    public function run(?string $path = null): array
    {
        $disk = Storage::disk((string) config('backup.disk'));
        $path ??= $this->newest();
        $work = storage_path('framework/drill-'.bin2hex(random_bytes(6)));

        if (! mkdir($work, 0700, true)) {
            throw new RuntimeException('Could not create the drill work folder.');
        }

        try {
            $zipPath = $work.'/backup.zip';
            $stream = $disk->readStream($path) ?? throw new RuntimeException("Backup {$path} could not be read.");
            file_put_contents($zipPath, $stream);
            fclose($stream);

            $zip = new ZipArchive;

            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('The backup is not a readable ZIP.');
            }

            // Closed on every path: Windows won't delete an open file, and the folder must go.
            try {
                $zip->setPassword((string) config('backup.password'));
                $manifestJson = $zip->getFromName('manifest.json');
                $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;

                if (! is_array($manifest) || ! isset($manifest['created_at'])) {
                    throw new RuntimeException('The manifest could not be read: wrong BACKUP_PASSWORD, or a damaged backup.');
                }

                if (! $zip->extractTo($work, 'database.sql')) {
                    throw new RuntimeException('The database dump could not be extracted.');
                }
            } finally {
                $zip->close();
            }

            $sql = $work.'/database.sql';
            $head = (string) file_get_contents($sql, false, null, 0, 4096);

            if (filesize($sql) === 0 || ! preg_match('/CREATE TABLE|INSERT INTO|-- (MySQL|MariaDB|PostgreSQL|Coffer)/i', $head)) {
                throw new RuntimeException('The database dump is empty or not SQL.');
            }

            $checks = ['the manifest and database dump decrypt and read ('.number_format((int) filesize($sql)).' bytes of SQL)'];
            $restored = false;

            if (is_string(config('backup.drill_database')) && config('backup.drill_database') !== '') {
                $checks = [...$checks, ...$this->restore($sql, $manifest)];
                $restored = true;
            }

            Cache::forever(self::HEARTBEAT, CarbonImmutable::now()->toIso8601String());

            return ['path' => $path, 'taken' => (string) $manifest['created_at'], 'restored' => $restored, 'checks' => $checks];
        } finally {
            $this->removeTree($work);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function restore(string $sql, array $manifest): array
    {
        $db = $this->drillConnection();
        $this->assertScratch($db);

        try {
            // Start empty, so tables the dump no longer has can't make a count pass.
            $db->getSchemaBuilder()->dropAllTables();
            $statements = $this->importer->import($db, $sql);
            $checks = ["loaded into the drill database ({$statements} statements)"];

            /** @var array<string, int> $expected */
            $expected = is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];

            if ($expected === []) {
                throw new RuntimeException('The manifest has no table counts to compare (a backup from before D150).');
            }

            $wrong = [];

            foreach ($expected as $table => $rows) {
                $actual = $db->getSchemaBuilder()->hasTable($table) ? $db->table($table)->count() : -1;

                if ($actual !== $rows) {
                    $wrong[] = "{$table} ({$actual} rows, expected {$rows})";
                }
            }

            if ($wrong !== []) {
                throw new RuntimeException('Restored row counts differ: '.implode(', ', $wrong).'.');
            }

            $checks[] = count($expected).' tables restored with the row counts recorded at backup time';

            if ($db->getSchemaBuilder()->hasTable('journal_lines')) {
                $totals = $db->table('journal_lines')->selectRaw('COALESCE(SUM(debit_paise), 0) AS dr, COALESCE(SUM(credit_paise), 0) AS cr')->first();

                if ((int) ($totals->dr ?? 0) !== (int) ($totals->cr ?? 0)) {
                    throw new RuntimeException('The restored books don’t balance.');
                }

                $checks[] = 'the restored books balance (debits = credits)';
            }

            if (LedgerGuard::supported($db)) {
                if (! LedgerGuard::installed($db)) {
                    throw new RuntimeException('The ledger guard triggers did not come back with the restore.');
                }

                $checks[] = 'the ledger guard triggers came back';
            }

            return $checks;
        } finally {
            $db->getSchemaBuilder()->dropAllTables();
            // Marks the database as the drill's own, so the next run may empty it again.
            $db->getSchemaBuilder()->create(self::MARKER, fn ($table) => $table->timestamp('drilled_at')->nullable());
            DB::purge('backup_drill');
        }
    }

    /**
     * The drill empties its database, so it only touches one that is empty or that it marked
     * itself: a BACKUP_DRILL_DATABASE pointing at some other real database is refused.
     */
    private function assertScratch(Connection $db): void
    {
        // This database only: without a schema, getTables() lists every database on the server.
        $tables = $db->getSchemaBuilder()->getTableListing($db->getDatabaseName(), schemaQualified: false);

        if ($tables !== [] && ! in_array(self::MARKER, $tables, true)) {
            throw new RuntimeException('BACKUP_DRILL_DATABASE already holds tables the drill didn’t create. Point it at an empty scratch database.');
        }
    }

    private function drillConnection(): Connection
    {
        $live = DB::connection();
        $database = (string) config('backup.drill_database');

        if ($database === $live->getDatabaseName()) {
            throw new RuntimeException('BACKUP_DRILL_DATABASE is the live database. Point it at an empty scratch database.');
        }

        config(['database.connections.backup_drill' => ['database' => $database] + $live->getConfig()]);

        return DB::connection('backup_drill');
    }

    private function newest(): string
    {
        $folder = trim((string) config('backup.path'), '/');
        $newest = collect(Storage::disk((string) config('backup.disk'))->files($folder))
            ->filter(fn (string $p): bool => preg_match('/coffer-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $p) === 1)
            ->sort()->last();

        return is_string($newest) ? $newest : throw new RuntimeException('There is no backup to drill yet. Run coffer:backup first.');
    }

    /**
     * The work folder holds the decrypted dump: it always goes, files first. On Windows a file
     * that was just written can stay locked for a moment (a virus scanner), so it tries again.
     */
    private function removeTree(string $dir): void
    {
        for ($attempt = 0; $attempt < 20 && is_dir($dir); $attempt++) {
            if ($attempt > 0) {
                usleep(100_000);
            }

            foreach (array_reverse($this->tree($dir)) as $path) {
                is_dir($path) ? @rmdir($path) : @unlink($path);
            }

            @rmdir($dir);
        }

        if (is_dir($dir)) {
            Log::warning('The restore drill could not remove its work folder', ['folder' => $dir]);
        }
    }

    /** @return list<string> every path under `$dir`, parents before children */
    private function tree(string $dir): array
    {
        $paths = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$name;
            $paths[] = $path;

            if (is_dir($path)) {
                $paths = [...$paths, ...$this->tree($path)];
            }
        }

        return $paths;
    }
}
