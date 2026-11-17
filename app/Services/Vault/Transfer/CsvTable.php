<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use RuntimeException;

/**
 * A CSV in memory for vault imports (D77): a header row then data rows, quoted fields
 * may span lines (notes do), a UTF-8 byte-order mark is dropped, `,` `;` or tab is
 * detected, blank lines skipped. Values are kept exactly (passwords can start or end
 * with spaces); only header names are trimmed. At most 5,000 rows.
 */
final readonly class CsvTable
{
    public const int MAX_ROWS = 5000;

    /**
     * @param  list<string>  $header
     * @param  list<array{line: int, cells: list<string>}>  $rows
     */
    private function __construct(public array $header, public array $rows) {}

    /** @throws RuntimeException with a message for the person */
    public static function read(string $bytes): self
    {
        $bytes = (string) preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $firstLine = strtok($bytes, "\n") ?: '';
        $delimiter = self::delimiter($firstLine);

        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('The file couldn’t be read.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        $header = null;
        $rows = [];
        $line = 0;

        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                continue;
            }

            $cells = array_map(fn ($c) => (string) $c, $cells);

            if ($header === null) {
                $header = array_map(fn (string $c) => trim($c), $cells);

                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($stream);

                throw new RuntimeException('This file has more than 5,000 rows. Split it into smaller files.');
            }

            $rows[] = ['line' => $line, 'cells' => array_pad(array_slice($cells, 0, count($header)), count($header), '')];
        }

        fclose($stream);

        if ($header === null || $rows === []) {
            throw new RuntimeException('This file has no rows to import. Check it has a header row and at least one item.');
        }

        return new self($header, $rows);
    }

    /**
     * Lower-cased header names, for format detection and mapping.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (string $h) => strtolower($h), $this->header);
    }

    /**
     * A row as name → value, by lower-cased header.
     *
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    public function assoc(array $cells): array
    {
        $out = [];

        foreach ($this->keys() as $i => $key) {
            $out[$key] ??= $cells[$i] ?? '';
        }

        return $out;
    }

    private static function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }
}
