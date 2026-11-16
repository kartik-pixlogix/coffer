import type { CompanyRole, CompanyType } from '@/types';

/*
 * House formats (design-system README › Content):
 *   money   ₹12,34,567 · −₹2,66,500 (true minus) · compact ₹2.7L / ₹1.2Cr
 *   dates   22 Oct 2026 · months Oct 2026 · FY 2026-27 (April–March)
 *   hours   2,157.5
 * Money is always integer paise in and out; never floats for storage.
 */

export const MINUS = '−';

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
] as const;

/* ---------------------------------------------------------------- numbers */

export type NumberFormat = 'indian' | 'international';

/** The viewer's digit grouping (spec §17), set once from their profile in app.tsx. */
let numberFormat: NumberFormat = 'indian';

export function setNumberFormat(format: NumberFormat): void {
    numberFormat = format;
}

export function getNumberFormat(): NumberFormat {
    return numberFormat;
}

/**
 * Group a non-negative integer string by the viewer's format:
 * Indian 1234567 → 12,34,567; International 1234567 → 1,234,567.
 */
function groupDigits(digits: string): string {
    if (digits.length <= 3) {
        return digits;
    }

    if (numberFormat === 'international') {
        return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    const last3 = digits.slice(-3);
    const rest = digits.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');

    return `${rest},${last3}`;
}

/** Group the integer part of any number the Indian way (sign not included). */
export function formatIndianNumber(value: number, fractionDigits = 0): string {
    const fixed = Math.abs(value).toFixed(fractionDigits);
    const [whole, fraction] = fixed.split('.');
    const grouped = groupDigits(whole);

    return fraction ? `${grouped}.${fraction}` : grouped;
}

/* ------------------------------------------------------------------ money */

export type MoneyFormatOptions = {
    /** 'auto' shows paise only when there are some (₹1,234.50); default 'auto'. */
    decimals?: 'auto' | 'always' | 'never';
    /** Prefix positive values with + (used for deltas). */
    signed?: boolean;
    /** Leave out the ₹ (for inputs and split units). */
    symbol?: boolean;
};

function assertPaise(paise: number): void {
    if (!Number.isSafeInteger(paise)) {
        throw new TypeError(
            `Money must be an integer number of paise, got ${paise}.`,
        );
    }
}

/** Absolute paise as "12,34,567" or "1,234.50", no symbol, no sign. */
export function formatPaiseDigits(
    paise: number,
    decimals: MoneyFormatOptions['decimals'] = 'auto',
): string {
    assertPaise(paise);

    const abs = Math.abs(paise);
    const rupees = Math.floor(abs / 100);
    const rest = abs % 100;
    const whole = groupDigits(String(rupees));

    if (decimals === 'always' || (decimals === 'auto' && rest !== 0)) {
        return `${whole}.${String(rest).padStart(2, '0')}`;
    }

    if (decimals === 'never' && rest >= 50) {
        return groupDigits(String(rupees + 1));
    }

    return whole;
}

/** ₹12,34,567 · −₹2,66,500 · ₹1,234.50 */
export function formatInr(
    paise: number,
    options: MoneyFormatOptions = {},
): string {
    const { decimals = 'auto', signed = false, symbol = true } = options;
    const digits = formatPaiseDigits(paise, decimals);
    const sign = paise < 0 ? MINUS : signed && paise > 0 ? '+' : '';

    return `${sign}${symbol ? '₹' : ''}${digits}`;
}

/** Chart and axis labels: ₹950 · ₹45K · ₹2.7L · ₹1.2Cr · −₹3.2L */
export function formatInrCompact(paise: number): string {
    assertPaise(paise);

    const rupees = Math.abs(paise) / 100;
    const sign = paise < 0 ? MINUS : '';
    const trim = (n: number) => String(Math.round(n * 10) / 10);

    if (numberFormat === 'international') {
        if (rupees >= 1_000_000_000) {
            return `${sign}₹${trim(rupees / 1_000_000_000)}B`;
        }

        if (rupees >= 1_000_000) {
            return `${sign}₹${trim(rupees / 1_000_000)}M`;
        }
    }

    if (numberFormat === 'indian' && rupees >= 1_00_00_000) {
        return `${sign}₹${trim(rupees / 1_00_00_000)}Cr`;
    }

    if (numberFormat === 'indian' && rupees >= 1_00_000) {
        return `${sign}₹${trim(rupees / 1_00_000)}L`;
    }

    if (rupees >= 1_000) {
        return `${sign}₹${trim(rupees / 1_000)}K`;
    }

    return `${sign}₹${Math.round(rupees)}`;
}

/**
 * Parse what a person typed into paise. Accepts "₹1,14,900", "114900.5", " 1,234.56 ".
 * Returns null for empty or invalid input; never accepts a minus (a refund is its own record).
 */
export function parseRupeesToPaise(input: string): number | null {
    const cleaned = input.replace(/[₹,\s]/g, '');

    if (!/^\d+(\.\d{0,2})?$/.test(cleaned)) {
        return null;
    }

    const [whole, fraction = ''] = cleaned.split('.');
    const paise = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));

    return Number.isSafeInteger(paise) ? paise : null;
}

/**
 * Re-group a partially typed amount as the person types: "1234567" → "12,34,567",
 * "1234." → "1,234.", "1234.567" → "1,234.56". Anything that isn't a digit or the
 * first dot is dropped.
 */
export function formatRupeeInput(raw: string): string {
    const stripped = raw.replace(/[^\d.]/g, '');
    const dot = stripped.indexOf('.');
    const wholeRaw = dot === -1 ? stripped : stripped.slice(0, dot);
    const fraction =
        dot === -1
            ? null
            : stripped
                  .slice(dot + 1)
                  .replace(/\./g, '')
                  .slice(0, 2);
    const whole = wholeRaw.replace(/^0+(?=\d)/, '');
    const grouped =
        whole === '' && fraction !== null ? '0' : groupDigits(whole);

    return fraction === null ? grouped : `${grouped}.${fraction}`;
}

/* ------------------------------------------------------------------ dates */

/** Local calendar date from "YYYY-MM-DD" or a Date (no timezone shift). */
export function toDate(value: Date | string): Date {
    if (value instanceof Date) {
        return value;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);

    if (match) {
        return new Date(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]),
        );
    }

    return new Date(value);
}

/** "YYYY-MM-DD" in local time. */
export function toIsoDate(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

/** "22 Oct 2026" */
export function formatDate(value: Date | string): string {
    const date = toDate(value);

    return `${date.getDate()} ${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
}

/** "Oct 2026" */
export function formatMonth(value: Date | string): string {
    const date = toDate(value);

    return `${MONTHS[date.getMonth()]} ${date.getFullYear()}`;
}

export const monthShortName = (monthIndex: number): string =>
    MONTHS[monthIndex];

/** "Sunday, 15 Nov 2026" — the top-bar date. */
export function formatLongDate(date: Date): string {
    const weekday = new Intl.DateTimeFormat('en-IN', {
        weekday: 'long',
    }).format(date);

    return `${weekday}, ${formatDate(date)}`;
}

function validDate(year: number, month: number, day: number): string | null {
    const date = new Date(year, month - 1, day);

    if (
        date.getFullYear() !== year ||
        date.getMonth() !== month - 1 ||
        date.getDate() !== day
    ) {
        return null;
    }

    return toIsoDate(date);
}

const expandYear = (year: string): number =>
    year.length === 2 ? 2000 + Number(year) : Number(year);

/**
 * Lenient date parsing for typed dates and CSV imports (spec §8.2). Returns "YYYY-MM-DD"
 * or null. Accepts 2026-04-01, 01/04/2026, 1/4/26, 01-04-2026, 1-Apr-26, 1 Apr 2026.
 * Slash and dash numeric dates are day-first (Indian convention).
 */
export function parseDateLenient(input: string): string | null {
    const value = input.trim();

    let match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(value);
    if (match) {
        return validDate(Number(match[1]), Number(match[2]), Number(match[3]));
    }

    match = /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$/.exec(value);
    if (match) {
        return validDate(
            expandYear(match[3]),
            Number(match[2]),
            Number(match[1]),
        );
    }

    match = /^(\d{1,2})[\s-]([A-Za-z]{3,9})[\s-,]*(\d{2}|\d{4})$/.exec(value);
    if (match) {
        const month = MONTHS.findIndex(
            (m) => m.toLowerCase() === match![2].slice(0, 3).toLowerCase(),
        );

        return month === -1
            ? null
            : validDate(expandYear(match[3]), month + 1, Number(match[1]));
    }

    return null;
}

/** First day of the fiscal year containing `date` (start month 1–12, April = 4). */
export function fiscalYearStart(date: Date, startMonth = 4): Date {
    const monthIndex = startMonth - 1;
    const year =
        date.getMonth() >= monthIndex
            ? date.getFullYear()
            : date.getFullYear() - 1;

    return new Date(year, monthIndex, 1);
}

/** "FY 2026-27" (or "FY 2026" when the fiscal year is the calendar year). */
export function fiscalYearLabel(date: Date, startMonth = 4): string {
    const start = fiscalYearStart(date, startMonth).getFullYear();

    if (startMonth === 1) {
        return `FY ${start}`;
    }

    return `FY ${start}-${String((start + 1) % 100).padStart(2, '0')}`;
}

/* ------------------------------------------------------------ hours & pct */

/** "2,157.5" — hours to one decimal with Indian grouping. */
export function formatHours(hours: number): string {
    return formatIndianNumber(Math.round(hours * 10) / 10, 1);
}

/** "12.5%" — trims a trailing ".0". */
export function formatPercent(value: number, fractionDigits = 1): string {
    const fixed = value.toFixed(fractionDigits).replace(/\.0+$/, '');

    return `${value < 0 ? MINUS : ''}${fixed.replace('-', '')}%`;
}

/* ------------------------------------------------------------- companies */

const COMPANY_TYPES: Record<CompanyType, string> = {
    single: 'Single',
    jv: 'Joint Venture',
};

const ROLES: Record<CompanyRole, string> = {
    admin: 'Admin',
    partner: 'Partner',
    member: 'Member',
    viewer: 'Viewer',
};

export const formatRole = (role: CompanyRole): string => ROLES[role];

/** "Joint Venture · Admin" — the second line of a company-switcher row. */
export const formatCompanyMeta = (type: CompanyType, role: CompanyRole) =>
    `${COMPANY_TYPES[type]} · ${ROLES[role]}`;
