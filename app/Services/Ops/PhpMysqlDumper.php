<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

/**
 * A MySQL / MariaDB dump written by PHP alone (Phase 10, D150), for hosts that can't run
 * mysqldump: shared hosting disables `proc_open`, so a scheduled backup there could never
 * start the binary (D146). Output is plain SQL a `mysql` client or `coffer:backup-drill` can
 * load: per table `DROP` + `CREATE` (from SHOW CREATE TABLE), then rows as multi-row INSERTs,
 * then the triggers (the ledger guard, D148) between `DELIMITER ;;` lines, all with foreign-key
 * checks off. Rows are read unbuffered, so memory stays flat on large tables.
 */
final class PhpMysqlDumper
{
    private const int ROWS_PER_INSERT = 200;

    /**
     * Dumps inside one consistent snapshot (unless the connection is already in a transaction,
     * which is its own snapshot), so the row counts it returns are exactly what it wrote.
     *
     * @return array<string, int> rows written per table
     */
    public function dump(Connection $db, string $toFile): array
    {
        $out = fopen($toFile, 'wb');

        if ($out === false) {
            throw new RuntimeException('Could not open the dump file for writing.');
        }

        $pdo = $db->getPdo();
        $ownSnapshot = $db->transactionLevel() === 0;
        $counts = [];

        if ($ownSnapshot) {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        try {
            $this->write($out, "-- Coffer database dump (PHP dumper, D150)\n-- ".gmdate('Y-m-d H:i:s')." UTC\n\n");
            $this->write($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            $tables = array_map(fn (array $row): string => (string) reset($row), $this->rows($pdo, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
            sort($tables);

            foreach ($tables as $table) {
                $counts[$table] = $this->table($pdo, $out, $table);
            }

            foreach ($this->rows($pdo, 'SHOW TRIGGERS') as $trigger) {
                $create = $this->rows($pdo, 'SHOW CREATE TRIGGER '.$this->name((string) $trigger['Trigger']))[0]['SQL Original Statement'] ?? null;

                if (is_string($create)) {
                    // DEFINER clauses name a user the restore target may not have.
                    $create = (string) preg_replace('/^CREATE\s+DEFINER\s*=\s*\S+\s+/i', 'CREATE ', $create);
                    $this->write($out, 'DROP TRIGGER IF EXISTS '.$this->name((string) $trigger['Trigger']).";\nDELIMITER ;;\n{$create};;\nDELIMITER ;\n\n");
                }
            }

            $this->write($out, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        } finally {
            fclose($out);

            if ($ownSnapshot) {
                $pdo->exec('COMMIT');
            }
        }

        return $counts;
    }

    /**
     * @param  resource  $out
     * @return int rows written
     */
    private function table(PDO $pdo, $out, string $table): int
    {
        $written = 0;
        $name = $this->name($table);
        $create = (string) ($this->rows($pdo, "SHOW CREATE TABLE {$name}")[0]['Create Table'] ?? '');

        if ($create === '') {
            throw new RuntimeException("Could not read the definition of {$table}.");
        }

        $this->write($out, "DROP TABLE IF EXISTS {$name};\n{$create};\n\n");

        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            $statement = $pdo->query("SELECT * FROM {$name}", PDO::FETCH_ASSOC);

            if ($statement === false) {
                throw new RuntimeException("Could not read {$table}.");
            }

            $columns = null;
            $values = [];

            foreach ($statement as $row) {
                /** @var array<string, mixed> $row */
                $columns ??= implode(', ', array_map(fn (string $c): string => $this->name($c), array_keys($row)));
                $values[] = '('.implode(', ', array_map(fn (mixed $v): string => $this->literal($pdo, $v), $row)).')';
                $written++;

                if (count($values) === self::ROWS_PER_INSERT) {
                    $this->write($out, "INSERT INTO {$name} ({$columns}) VALUES\n".implode(",\n", $values).";\n");
                    $values = [];
                }
            }

            if ($values !== []) {
                $this->write($out, "INSERT INTO {$name} ({$columns}) VALUES\n".implode(",\n", $values).";\n");
            }

            $statement->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }

        $this->write($out, "\n");

        return $written;
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql, PDO::FETCH_ASSOC);

        if ($statement === false) {
            throw new RuntimeException('The dump query failed.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    private function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            // Binary-safe: anything that isn't valid UTF-8 goes as hex.
            is_string($value) && ! mb_check_encoding($value, 'UTF-8') => '0x'.bin2hex($value),
            default => (string) $pdo->quote((string) $value),
        };
    }

    private function name(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /** @param resource $out */
    private function write($out, string $text): void
    {
        if (fwrite($out, $text) === false) {
            throw new RuntimeException('Could not write the dump (disk full?).');
        }
    }
}
