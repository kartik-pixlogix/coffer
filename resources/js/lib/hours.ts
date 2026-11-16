import { formatIndianNumber } from '@/lib/format';

/**
 * Hours travel as integer hundredths of an hour (D35): 2.5 h = 250. These helpers
 * mirror app/Services/Timesheets/Hours.php so previews match what the server saves.
 */

/** "8.0", "2.5", "57.33" (Timesheets.dc.html); `minDecimals` 0 gives "142" (Team). */
export function formatCenti(centi: number, minDecimals = 1): string {
    const decimals = centi % 100 === 0 ? minDecimals : centi % 10 === 0 ? 1 : 2;

    return `${centi < 0 ? '−' : ''}${formatIndianNumber(Math.abs(centi) / 100, decimals)}`;
}

/**
 * "2.5" or "2:30" (two and a half hours), "57:20" (57 h 20 min) → hundredths, rounded
 * to the nearest hundredth. Null when it isn't a number of hours.
 */
export function parseHours(input: string): number | null {
    const value = input.trim();

    let match = /^(\d{1,4}):([0-5]?\d)$/.exec(value);

    if (match) {
        return (
            Number(match[1]) * 100 + Math.round((Number(match[2]) * 100) / 60)
        );
    }

    match = /^(\d{0,4})(?:\.(\d*))?$/.exec(value);

    if (match && (match[1] !== '' || (match[2] ?? '') !== '')) {
        const whole = Number(match[1] || '0');
        const fraction = (match[2] ?? '').padEnd(3, '0');
        const rounded = Math.round(Number(fraction.slice(0, 3)) / 10);

        return whole * 100 + rounded;
    }

    return null;
}

export const MIN_ENTRY_CENTI = 25;
export const MAX_ENTRY_CENTI = 2400;
/** More than this in a day for one member asks "are you sure?" (spec §8.1). */
export const LONG_DAY_CENTI = 1200;

/** ISO weekday bit: Monday = 1, Tuesday = 2 … Saturday = 32, Sunday = 64. */
export const dayBit = (date: Date): number => 1 << ((date.getDay() + 6) % 7);
export const SATURDAY = 32;

/** The month's working days ("YYYY-MM-DD"), from the company's working-day mask. */
export function workingDays(
    year: number,
    monthIndex: number,
    mask: number,
): string[] {
    const days: string[] = [];
    const count = new Date(year, monthIndex + 1, 0).getDate();

    for (let d = 1; d <= count; d++) {
        const date = new Date(year, monthIndex, d);

        if (mask & dayBit(date)) {
            days.push(
                `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`,
            );
        }
    }

    return days;
}

/**
 * Spread a month's total over its days in hundredths, largest remainder first, so the
 * parts add up to the total exactly (spec §8.1). Earlier days take the spare hundredths.
 */
export function distribute(totalCenti: number, days: number): number[] {
    if (days <= 0) {
        return [];
    }

    const base = Math.floor(totalCenti / days);
    const extra = totalCenti - base * days;

    return Array.from({ length: days }, (_, i) => base + (i < extra ? 1 : 0));
}

/** "21 working days → 2.73 hrs/day" (Timesheets.dc.html month preview). */
export function perDayLabel(totalCenti: number, days: number): string {
    const perDay = days > 0 ? totalCenti / 100 / days : 0;

    return `${days} working days → ${perDay.toFixed(2)} hrs/day`;
}
