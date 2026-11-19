<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every CSV the app writes goes through here (G3, D107): a text cell that a spreadsheet
 * would read as a formula (it starts with = + - @, a tab or a carriage return) gets a
 * leading single quote, so "=HYPERLINK(…)" in an expense description stays text.
 * Plain numbers ("-1250.50") are left alone: they can't be formulas and must stay numbers.
 */
final class Csv
{
    /** Neutralise one cell. */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = is_scalar($value) ? (string) $value : (string) json_encode($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) === 1) {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    /**
     * @param  array<array-key, mixed>  $cells
     * @return list<string>
     */
    public static function row(array $cells): array
    {
        return array_map(self::cell(...), array_values($cells));
    }

    /**
     * Write one neutralised row to an open stream.
     *
     * @param  resource  $stream
     * @param  array<array-key, mixed>  $cells
     */
    public static function put($stream, array $cells): void
    {
        fputcsv($stream, self::row($cells), escape: '');
    }
}
