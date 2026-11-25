<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Writes export workbooks with spatie/simple-excel (D61). Money cells hold rupees as
 * numbers (paise / 100, exact to the paisa) with an Indian-grouped ₹ format, percentages
 * hold fractions with a 0.0% format, so the sheets add up in Excel. A cell is a plain
 * value, `Xlsx::money($paise)` or `Xlsx::percent($bps)`.
 */
final class Xlsx
{
    /** Lakh and crore grouping (Excel allows two conditions; negatives fall to the last section). */
    public const string MONEY_FORMAT = '[>=10000000]"₹"##\,##\,##\,##0.00;[>=100000]"₹"##\,##\,##0.00;"₹"#,##0.00';

    public const string PERCENT_FORMAT = '0.0%';

    /** @return array{paise: int} */
    public static function money(int $paise): array
    {
        return ['paise' => $paise];
    }

    /** @return array{bps: int|null} */
    public static function percent(?int $bps): array
    {
        return ['bps' => $bps];
    }

    /**
     * @param  list<array{name: string, header: list<string>, rows: list<list<int|string|null|array{paise: int}|array{bps: int|null}>>, totalLast?: bool}>  $sheets
     * @return string path of the written .xlsx (a temp file the caller removes)
     */
    public function write(array $sheets): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'coffer-xlsx-');
        @unlink($base);
        $path = $base.'.xlsx';

        $writer = SimpleExcelWriter::create($path)->noHeaderRow();

        foreach ($sheets as $i => $sheet) {
            if ($i === 0) {
                $writer->nameCurrentSheet($sheet['name']);
            } else {
                $writer->addNewSheetAndMakeItCurrent($sheet['name']);
            }

            $writer->addRow(Row::fromValues($sheet['header'], self::style(bold: true)));
            $last = count($sheet['rows']) - 1;

            foreach ($sheet['rows'] as $r => $values) {
                $bold = ($sheet['totalLast'] ?? false) && $r === $last;
                $cells = [];

                foreach ($values as $value) {
                    if (is_string($value)) {
                        // G3 (D107): text is always a text cell, never a formula ("=…" from a description).
                        $cells[] = new StringCell($value, self::style($bold));
                    } elseif (! is_array($value)) {
                        $cells[] = Cell::fromValue($value, self::style($bold));
                    } elseif (isset($value['paise'])) {
                        $cells[] = Cell::fromValue(self::rupees($value['paise']), self::style($bold, self::MONEY_FORMAT));
                    } else {
                        $bps = $value['bps'] ?? null;
                        $cells[] = Cell::fromValue($bps === null ? null : $bps / 10000, self::style($bold, self::PERCENT_FORMAT));
                    }
                }

                $writer->addRow(new Row($cells));
            }
        }

        $writer->close();

        return $path;
    }

    /** A cell style (openspout 4's styles are mutable, so each cell gets its own). */
    private static function style(bool $bold = false, ?string $format = null): Style
    {
        $style = new Style;

        if ($bold) {
            $style->setFontBold();
        }

        if ($format !== null) {
            $style->setFormat($format);
        }

        return $style;
    }

    /** Paise as rupees: a whole number when there are no paise, else to two places. */
    public static function rupees(int $paise): int|float
    {
        return $paise % 100 === 0 ? intdiv($paise, 100) : round($paise / 100, 2);
    }

    /**
     * Rows for a "Notes" sheet of a Schedule III statement (D156): each note's accounts, then its total.
     *
     * @param  list<array{number: int, title: string, subtitle: string|null, rows: list<array{code: string, name: string, amount: int, previous: int|null}>, total: int, previousTotal: int|null}>  $notes
     * @return list<list<int|string|null|array{paise: int}>>
     */
    public static function noteRows(array $notes, bool $withPrevious): array
    {
        $m = self::money(...);
        $rows = [];

        foreach ($notes as $note) {
            foreach ($note['rows'] as $row) {
                $rows[] = [$note['number'].'. '.$note['title'], $row['code'], $row['name'], $m($row['amount']), ...($withPrevious ? [$row['previous'] === null ? null : $m($row['previous'])] : [])];
            }

            $rows[] = [$note['number'].'. '.$note['title'], null, 'Total', $m($note['total']), ...($withPrevious ? [$note['previousTotal'] === null ? null : $m($note['previousTotal'])] : [])];
        }

        return $rows;
    }
}
