import { toDate, toIsoDate } from '@/lib/format';

/** Monday-first weekday headings. */
export const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as const;

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

/** "October 2026" (calendar heading). */
export const monthTitle = (year: number, month: number): string =>
    `${MONTH_NAMES[month]} ${year}`;

/** Shift a "YYYY-MM-DD" date by whole days. */
export function addDays(iso: string, days: number): string {
    const date = toDate(iso);

    return toIsoDate(
        new Date(date.getFullYear(), date.getMonth(), date.getDate() + days),
    );
}

/** Shift by whole months, keeping the day where possible (31 Jan + 1 → 28/29 Feb). */
export function addMonths(iso: string, months: number): string {
    const date = toDate(iso);
    const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const lastDay = new Date(
        target.getFullYear(),
        target.getMonth() + 1,
        0,
    ).getDate();

    return toIsoDate(
        new Date(
            target.getFullYear(),
            target.getMonth(),
            Math.min(date.getDate(), lastDay),
        ),
    );
}

/** Monday-first weekday index: Monday 0 … Sunday 6. */
export const mondayIndex = (date: Date): number => (date.getDay() + 6) % 7;

export type DateRange = { start: string | null; end: string | null };

export type DateRules = {
    /** Earliest allowed date, "YYYY-MM-DD" (e.g. the first day of the open FY). */
    min?: string;
    max?: string;
    /** Specific dates that can't be picked. */
    disabledDates?: readonly string[];
    /** Any other rule (closed FY, weekends). */
    isDateDisabled?: (iso: string) => boolean;
};

/** Can this date be picked under the rules? ISO strings compare correctly as text. */
export function isDateAllowed(iso: string, rules: DateRules): boolean {
    if (rules.min !== undefined && iso < rules.min) {
        return false;
    }

    if (rules.max !== undefined && iso > rules.max) {
        return false;
    }

    if (rules.disabledDates?.includes(iso)) {
        return false;
    }

    return !(rules.isDateDisabled?.(iso) ?? false);
}

export type CalendarDay = {
    iso: string;
    /** Day of the month, 1–31. */
    day: number;
    /** False for the leading/trailing days of the neighbouring months (`.is-muted`). */
    inMonth: boolean;
    isToday: boolean;
    /** Single selection, or a range end (`aria-pressed`). */
    isSelected: boolean;
    /** Strictly between the range ends (`.is-range`). */
    inRange: boolean;
    disabled: boolean;
};

export type CalendarGridOptions = DateRules & {
    year: number;
    /** 0–11 */
    month: number;
    /** "YYYY-MM-DD" for today (pass it in so the grid is deterministic). */
    today: string;
    selected?: string | null;
    range?: DateRange;
};

/**
 * The days shown for one month, Monday first, in whole weeks: leading days from the
 * previous month and trailing days from the next fill the first and last rows
 * (4 to 6 rows, as many as the month needs).
 */
export function buildCalendarGrid({
    year,
    month,
    today,
    selected = null,
    range,
    ...rules
}: CalendarGridOptions): CalendarDay[] {
    const first = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const lead = mondayIndex(first);
    const total = Math.ceil((lead + daysInMonth) / 7) * 7;
    const start = range?.start ?? null;
    const end = range?.end ?? null;
    const days: CalendarDay[] = [];

    for (let cell = 0; cell < total; cell++) {
        const date = new Date(year, month, 1 - lead + cell);
        const iso = toIsoDate(date);

        days.push({
            iso,
            day: date.getDate(),
            inMonth: date.getMonth() === month,
            isToday: iso === today,
            isSelected: range ? iso === start || iso === end : iso === selected,
            inRange: start !== null && end !== null && iso > start && iso < end,
            disabled: !isDateAllowed(iso, rules),
        });
    }

    return days;
}

/** Chunk a flat grid into weeks of seven. */
export function toWeeks<T>(days: readonly T[]): T[][] {
    const weeks: T[][] = [];

    for (let index = 0; index < days.length; index += 7) {
        weeks.push(days.slice(index, index + 7));
    }

    return weeks;
}

/**
 * Where keyboard focus moves in the grid: arrows by day/week, PageUp/PageDown by
 * month (Shift: by year), Home/End to the start/end of the week. Null for other keys.
 */
export function moveFocus(
    iso: string,
    key: string,
    shiftKey = false,
): string | null {
    switch (key) {
        case 'ArrowLeft':
            return addDays(iso, -1);
        case 'ArrowRight':
            return addDays(iso, 1);
        case 'ArrowUp':
            return addDays(iso, -7);
        case 'ArrowDown':
            return addDays(iso, 7);
        case 'PageUp':
            return addMonths(iso, shiftKey ? -12 : -1);
        case 'PageDown':
            return addMonths(iso, shiftKey ? 12 : 1);
        case 'Home':
            return addDays(iso, -mondayIndex(toDate(iso)));
        case 'End':
            return addDays(iso, 6 - mondayIndex(toDate(iso)));
        default:
            return null;
    }
}

/**
 * Parse a typed range such as "1 Apr 2026 – 31 Mar 2027" or "01/04/26 to 31/03/27"
 * with a lenient single-date parser. Returns null if either side is invalid or the
 * end is before the start.
 */
export function parseRangeText(
    text: string,
    parse: (value: string) => string | null,
): { start: string; end: string } | null {
    const parts = text.split(/\s+(?:–|—|-|to)\s+/i);

    if (parts.length !== 2) {
        return null;
    }

    const start = parse(parts[0]);
    const end = parse(parts[1]);

    if (start === null || end === null || end < start) {
        return null;
    }

    return { start, end };
}
