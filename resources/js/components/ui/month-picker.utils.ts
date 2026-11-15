import { fiscalYearLabel, fiscalYearStart, monthShortName } from '@/lib/format';

/** "YYYY-MM" for a year and a 0-based month. */
export const toMonthValue = (year: number, monthIndex: number): string =>
    `${year}-${String(monthIndex + 1).padStart(2, '0')}`;

/** Parse "YYYY-MM" into a Date on the 1st (local time). */
export function monthValueToDate(value: string): Date {
    const [year, month] = value.split('-').map(Number);

    return new Date(year, month - 1, 1);
}

/** Shift "YYYY-MM" by whole months. */
export function addMonthsToValue(value: string, months: number): string {
    const date = monthValueToDate(value);
    const shifted = new Date(date.getFullYear(), date.getMonth() + months, 1);

    return toMonthValue(shifted.getFullYear(), shifted.getMonth());
}

/** The calendar year a fiscal year starts in, for the FY holding "YYYY-MM". */
export const fiscalYearOf = (value: string, startMonth = 4): number =>
    fiscalYearStart(monthValueToDate(value), startMonth).getFullYear();

export type FiscalMonth = {
    /** "YYYY-MM" */
    value: string;
    /** "Apr" */
    label: string;
    selected: boolean;
    current: boolean;
    disabled: boolean;
};

export type FiscalYearMonthsOptions = {
    /** Calendar year the FY starts in (2026 for FY 2026-27). */
    fyStartYear: number;
    /** First month of the FY, 1–12 (April = 4). */
    startMonth?: number;
    selected?: string | null;
    /** This month, "YYYY-MM". */
    current?: string;
    /** Earliest and latest months that can hold entries, "YYYY-MM" (inclusive). */
    min?: string;
    max?: string;
};

/** The twelve months of one fiscal year, in order (April to March by default). */
export function buildFiscalYearMonths({
    fyStartYear,
    startMonth = 4,
    selected = null,
    current,
    min,
    max,
}: FiscalYearMonthsOptions): FiscalMonth[] {
    return Array.from({ length: 12 }, (_, offset) => {
        const date = new Date(fyStartYear, startMonth - 1 + offset, 1);
        const value = toMonthValue(date.getFullYear(), date.getMonth());

        return {
            value,
            label: monthShortName(date.getMonth()),
            selected: value === selected,
            current: value === current,
            // "YYYY-MM" strings compare correctly as text.
            disabled:
                (min !== undefined && value < min) ||
                (max !== undefined && value > max),
        };
    });
}

/** "FY 2026-27" for the FY starting in `fyStartYear`. */
export const fiscalYearTitle = (fyStartYear: number, startMonth = 4): string =>
    fiscalYearLabel(new Date(fyStartYear, startMonth - 1, 1), startMonth);
