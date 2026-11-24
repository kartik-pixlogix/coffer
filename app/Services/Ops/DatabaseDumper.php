<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Writes a plain SQL dump of the default connection (D96, D150). MySQL / MariaDB: the PHP
 * dumper by default (`BACKUP_DUMPER=php`; it needs no binary, so it works where `proc_open`
 * is disabled, and it reports exact row counts for the restore drill), or mysqldump
 * (`BACKUP_DUMPER=mysqldump`). PostgreSQL: pg_dump. The password never goes on a command
 * line: mysqldump reads it from a temporary option file, pg_dump from PGPASSWORD.
 */
class DatabaseDumper
{
    public function __construct(private readonly PhpMysqlDumper $php) {}

    /** @return array<string, int>|null rows per table when the dumper knows them exactly */
    public function dump(string $toFile): ?array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        /** @var array{host?: string, port?: int|string, database?: string, username?: string, password?: string} $config */
        $config = $connection->getConfig();
        $counts = null;

        match (true) {
            in_array($driver, ['mysql', 'mariadb'], true) && config('backup.dumper') !== 'mysqldump' => $counts = $this->phpDump($toFile),
            in_array($driver, ['mysql', 'mariadb'], true) => $this->mysql($config, $toFile),
            $driver === 'pgsql' => $this->postgres($config, $toFile),
            default => throw new RuntimeException("Backups support MySQL, MariaDB and PostgreSQL, not {$driver}."),
        };

        if (! is_file($toFile) || filesize($toFile) === 0) {
            throw new RuntimeException('The database dump came out empty.');
        }

        return $counts;
    }

    /**
     * On a connection of its own, so its snapshot transaction never mixes with the caller's.
     *
     * @return array<string, int>
     */
    private function phpDump(string $toFile): array
    {
        config(['database.connections.backup_dump' => DB::connection()->getConfig()]);

        try {
            return $this->php->dump(DB::connection('backup_dump'), $toFile);
        } finally {
            DB::purge('backup_dump');
        }
    }

    /** @param  array{host?: string, port?: int|string, database?: string, username?: string, password?: string}  $config */
    private function mysql(array $config, string $toFile): void
    {
        $options = tempnam(sys_get_temp_dir(), 'cfdb');

        if ($options === false) {
            throw new RuntimeException('Could not create a temporary file for the dump.');
        }

        file_put_contents($options, implode("\n", [
            '[client]',
            'user="'.addslashes((string) ($config['username'] ?? '')).'"',
            'password="'.addslashes((string) ($config['password'] ?? '')).'"',
            'host="'.addslashes((string) ($config['host'] ?? '127.0.0.1')).'"',
            'port='.(int) ($config['port'] ?? 3306),
            '',
        ]));

        try {
            $result = Process::timeout((int) config('backup.timeout'))->run([
                (string) config('backup.mysqldump'),
                '--defaults-extra-file='.$options,
                '--single-transaction',
                '--quick',
                '--routines',
                '--no-tablespaces',
                '--result-file='.$toFile,
                (string) ($config['database'] ?? ''),
            ]);
        } finally {
            @unlink($options);
        }

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }
    }

    /** @param  array{host?: string, port?: int|string, database?: string, username?: string, password?: string}  $config */
    private function postgres(array $config, string $toFile): void
    {
        $result = Process::timeout((int) config('backup.timeout'))
            ->env(['PGPASSWORD' => (string) ($config['password'] ?? '')])
            ->run([
                (string) config('backup.pg_dump'),
                '--no-owner',
                '--format=plain',
                '--file='.$toFile,
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? 5432),
                '--username='.($config['username'] ?? ''),
                (string) ($config['database'] ?? ''),
            ]);

        if ($result->failed()) {
            throw new RuntimeException('pg_dump failed: '.trim($result->errorOutput()));
        }
    }
}
