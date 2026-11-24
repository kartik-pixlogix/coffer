<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Loads a plain SQL dump (the PHP dumper's or mysqldump's) into a connection, statement by
 * statement, without the `mysql` client (D150, for the restore drill on hosts without one).
 * Understands quoted strings, `--` / `#` / `/* *\/` comments and `DELIMITER` lines, so
 * trigger bodies with `;` inside them load whole.
 */
final class SqlImporter
{
    /** @return int statements run */
    public function import(Connection $db, string $file): int
    {
        $in = fopen($file, 'rb');

        if ($in === false) {
            throw new RuntimeException('Could not open the SQL dump.');
        }

        $delimiter = ';';
        $statement = '';
        $count = 0;
        $quote = null;
        $blockComment = false;

        try {
            while (($line = fgets($in)) !== false) {
                if ($quote === null && ! $blockComment && trim($statement) === '' && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m) === 1) {
                    $delimiter = $m[1];

                    continue;
                }

                $length = strlen($line);

                for ($i = 0; $i < $length; $i++) {
                    $char = $line[$i];

                    if ($blockComment) {
                        if ($char === '*' && ($line[$i + 1] ?? '') === '/') {
                            $blockComment = false;
                            $i++;
                        }

                        continue;
                    }

                    if ($quote !== null) {
                        $statement .= $char;

                        if ($char === '\\') {
                            $statement .= $line[++$i] ?? '';
                        } elseif ($char === $quote) {
                            $quote = null;
                        }

                        continue;
                    }

                    if ($char === "'" || $char === '"' || $char === '`') {
                        $quote = $char;
                        $statement .= $char;

                        continue;
                    }

                    // Comments outside strings. `/*! … */` version comments carry real SQL (mysqldump), so they stay.
                    if ($char === '#' || ($char === '-' && ($line[$i + 1] ?? '') === '-' && in_array($line[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true))) {
                        break;
                    }

                    if ($char === '/' && ($line[$i + 1] ?? '') === '*' && ($line[$i + 2] ?? '') !== '!') {
                        $blockComment = true;
                        $i++;

                        continue;
                    }

                    if (substr($line, $i, strlen($delimiter)) === $delimiter) {
                        $this->run($db, $statement, $count);
                        $statement = '';
                        $i += strlen($delimiter) - 1;

                        continue;
                    }

                    $statement .= $char;
                }

                if ($quote !== null) {
                    // A newline inside a string is part of the value; it was copied char by char above.
                    continue;
                }

                $statement .= $statement === '' ? '' : "\n";
            }

            $this->run($db, $statement, $count);
        } finally {
            fclose($in);
        }

        return $count;
    }

    private function run(Connection $db, string $statement, int &$count): void
    {
        $statement = trim($statement);

        if ($statement === '') {
            return;
        }

        // The statements come from our own backup file, run as-is on the drill connection.
        if ($db->getPdo()->exec($statement) === false) {
            throw new RuntimeException('A statement in the dump failed.');
        }

        $count++;
    }
}
