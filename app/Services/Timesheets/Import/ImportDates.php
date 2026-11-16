<?php

declare(strict_types=1);

namespace App\Services\Timesheets\Import;

use Carbon\CarbonImmutable;

/**
 * Dates in a timesheet CSV (spec §8.2): `2026-04-01`, `01/04/2026`, `1-Apr-26`, and
 * `2026-04` (a monthly total). Numeric dates are day-first unless the file shows
 * otherwise (a row like 04/13/2026 can only be month-first).
 */
final class ImportDates
{
    public const string DAY_FIRST = 'dmy';

    public const string MONTH_FIRST = 'mdy';

    private const array MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * Which way numeric dates run: month-first only when a value can't be day-first
     * (second part above 12) and none needs day-first.
     *
     * @param  iterable<string>  $values
     */
    public static function order(iterable $values): string
    {
        $dayFirst = false;
        $monthFirst = false;

        foreach ($values as $value) {
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$#', trim($value), $m) === 1) {
                $dayFirst = $dayFirst || (int) $m[1] > 12;
                $monthFirst = $monthFirst || (int) $m[2] > 12;
            }
        }

        return $monthFirst && ! $dayFirst ? self::MONTH_FIRST : self::DAY_FIRST;
    }

    /**
     * How numeric dates were read, for the mapping step: "Dates read as day-month-year
     * because one row has 13/10/2026." Null when the file has no numeric dates.
     *
     * @param  iterable<string>  $values
     */
    public static function explain(iterable $values): ?string
    {
        $numeric = [];

        foreach ($values as $value) {
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$#', trim($value), $m) === 1) {
                $numeric[] = [trim($value), (int) $m[1], (int) $m[2]];
            }
        }

        if ($numeric === []) {
            return null;
        }

        $order = self::order(array_column($numeric, 0));

        foreach ($numeric as [$value, $first, $second]) {
            if ($order === self::DAY_FIRST && $first > 12) {
                return "Dates read as day-month-year because one row has {$value}.";
            }

            if ($order === self::MONTH_FIRST && $second > 12) {
                return "Dates read as month-day-year because one row has {$value}.";
            }
        }

        return "Dates like {$numeric[0][0]} read as day-month-year.";
    }

    /**
     * @return array{kind: 'day'|'month', date: CarbonImmutable}|array{kind: 'impossible', message: string}|null
     *                                                                                                           null when it isn't a date at all
     */
    public static function parse(string $raw, string $order = self::DAY_FIRST): ?array
    {
        $value = trim($raw);

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m) === 1) {
            $month = (int) $m[2];

            return $month >= 1 && $month <= 12
                ? ['kind' => 'month', 'date' => CarbonImmutable::create((int) $m[1], $month, 1)?->startOfDay() ?? CarbonImmutable::today()]
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1) {
            return self::day((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$#', $value, $m) === 1) {
            [$day, $month] = $order === self::MONTH_FIRST ? [(int) $m[2], (int) $m[1]] : [(int) $m[1], (int) $m[2]];

            return self::day(self::year($m[3]), $month, $day);
        }

        if (preg_match('/^(\d{1,2})[\s-]([A-Za-z]{3,9})[\s,-]*(\d{2}|\d{4})$/', $value, $m) === 1) {
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;

            return $month === null ? null : self::day(self::year($m[3]), $month, (int) $m[1]);
        }

        return null;
    }

    /** @return array{kind: 'day', date: CarbonImmutable}|array{kind: 'impossible', message: string}|null */
    private static function day(int $year, int $month, int $day): ?array
    {
        if ($month < 1 || $month > 12 || $day < 1) {
            return null;
        }

        if (! checkdate($month, $day, $year)) {
            $name = CarbonImmutable::create($year, $month, 1)?->format('F') ?? '';

            return $day <= 31
                ? ['kind' => 'impossible', 'message' => "{$day} {$name} doesn’t exist."]
                : null;
        }

        return ['kind' => 'day', 'date' => CarbonImmutable::create($year, $month, $day)?->startOfDay() ?? CarbonImmutable::today()];
    }

    private static function year(string $digits): int
    {
        return strlen($digits) === 2 ? 2000 + (int) $digits : (int) $digits;
    }
}
