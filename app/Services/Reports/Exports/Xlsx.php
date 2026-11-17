<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use OpenSpout\Common\Entity\Cell;
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
                    if (! is_array($value)) {
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
}
