<?php

declare(strict_types=1);

namespace App\Services\Timesheets\Import;

use RuntimeException;
use SplFileObject;

/**
 * Reads a timesheet CSV (spec §8.2) with PHP's own parser: a header row then data
 * rows. Strips a UTF-8 byte-order mark, detects `,` `;` or tab delimiters and skips
 * blank lines. At most 5,000 data rows.
 */
final class CsvFile
{
    public const int MAX_ROWS = 5000;

    public const int MAX_KB = 5120;

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function __construct(public readonly array $header, public readonly array $rows) {}

    /** @throws RuntimeException with a message for the person when the file can't be used */
    public static function read(string $path): self
    {
        $file = new SplFileObject($path, 'r');
        $first = (string) $file->fgets();
        $file->rewind();

        $delimiter = self::delimiter($first);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '');

        $header = null;
        $rows = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null] || self::blank($line)) {
                continue;
            }

            $cells = array_map(fn ($cell) => trim((string) $cell), array_values($line));

            if ($header === null) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]) ?? $cells[0];
                $header = $cells;

                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new RuntimeException('This file has more than 5,000 rows. Split it into smaller files.');
            }

            $rows[] = array_pad(array_slice($cells, 0, count($header)), count($header), '');
        }

        if ($header === null || $rows === []) {
            throw new RuntimeException('This file has no rows to import. Check it has a header row and at least one entry.');
        }

        return new self($header, $rows);
    }

    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /** @param array<int, mixed> $line */
    private static function blank(array $line): bool
    {
        foreach ($line as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
