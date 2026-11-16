<?php

declare(strict_types=1);

namespace App\Services\Timesheets;

use App\Ledger\Rounding;
use Carbon\CarbonImmutable;

/**
 * Hours as integer hundredths of an hour (D35): parsing what people type or import,
 * working days in a month and the month-mode split (spec §8.1). Mirrored by
 * resources/js/lib/hours.ts for live previews.
 */
final class Hours
{
    /** 0.25 h to 24 h per entry (spec §8.1). */
    public const int MIN = 25;

    public const int MAX = 2400;

    /** Above this per member per day, warn (spec §8.1). */
    public const int LONG_DAY = 1200;

    /** Working-day bitmask: Monday = 1 … Saturday = 32, Sunday = 64 (companies.working_days). */
    public const int SATURDAY = 32;

    public const int WEEKDAYS = 31;

    /**
     * "2.5" or "2:30" (both two and a half hours), "57:20" → hundredths, rounded to the
     * nearest hundredth. Null when it isn't a number of hours.
     */
    public static function parse(string $input): ?int
    {
        $value = trim($input);

        if (preg_match('/^(\d{1,4}):([0-5]?\d)$/', $value, $m) === 1) {
            return (int) $m[1] * 100 + (int) round((int) $m[2] * 100 / 60);
        }

        if (preg_match('/^(\d{0,4})(?:\.(\d*))?$/', $value, $m) === 1 && ($m[1] !== '' || ($m[2] ?? '') !== '')) {
            $fraction = substr(str_pad($m[2] ?? '', 3, '0'), 0, 3);

            return (int) ($m[1] === '' ? 0 : $m[1]) * 100 + (int) round((int) $fraction / 10);
        }

        return null;
    }

    /** "8.0", "2.5", "57.33" (Timesheets.dc.html). */
    public static function format(int $centi): string
    {
        $decimals = $centi % 10 === 0 ? 1 : 2;

        return number_format($centi / 100, $decimals, '.', ',');
    }

    /**
     * The month's working days under a weekday mask.
     *
     * @return list<CarbonImmutable>
     */
    public static function workingDays(CarbonImmutable $month, int $mask): array
    {
        $days = [];

        for ($day = $month->startOfMonth(); $day->month === $month->month; $day = $day->addDay()) {
            if (($mask & (1 << ($day->dayOfWeekIso - 1))) !== 0) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /** A company's mask with Saturdays switched on or off (the "Include Saturdays" toggle). */
    public static function mask(int $companyMask, bool $saturdays): int
    {
        return $saturdays ? $companyMask | self::SATURDAY : $companyMask & ~self::SATURDAY;
    }

    /**
     * Split a total over `$days` days in hundredths, largest remainder first, so the parts
     * add up to the total exactly; earlier days take the spare hundredths.
     *
     * @return list<int>
     */
    public static function distribute(int $totalCenti, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        return array_values(Rounding::largestRemainder($totalCenti, array_fill(0, $days, 1)));
    }
}
