import { describe, expect, it } from 'vite-plus/test';
import {
    addMonthsToValue,
    buildFiscalYearMonths,
    fiscalYearOf,
    fiscalYearTitle,
} from './month-picker.utils';

describe('buildFiscalYearMonths', () => {
    const months = buildFiscalYearMonths({
        fyStartYear: 2026,
        selected: '2026-10',
        current: '2026-11',
        max: '2026-11',
    });

    it('runs April to March across two calendar years', () => {
        expect(months.map((m) => m.label).join(' ')).toBe(
            'Apr May Jun Jul Aug Sep Oct Nov Dec Jan Feb Mar',
        );
        expect(months[0].value).toBe('2026-04');
        expect(months[11].value).toBe('2027-03');
    });

    it('marks selected, current and future months', () => {
        expect(months.find((m) => m.selected)?.value).toBe('2026-10');
        expect(months.find((m) => m.current)?.value).toBe('2026-11');
        expect(
            months
                .filter((m) => m.disabled)
                .map((m) => m.label)
                .join(' '),
        ).toBe('Dec Jan Feb Mar');
    });

    it('honours a minimum and another start month', () => {
        const calendarYear = buildFiscalYearMonths({
            fyStartYear: 2026,
            startMonth: 1,
            min: '2026-03',
        });

        expect(calendarYear[0].value).toBe('2026-01');
        expect(calendarYear.filter((m) => m.disabled)).toHaveLength(2);
    });
});

describe('fiscal year helpers', () => {
    it('finds the FY a month belongs to', () => {
        expect(fiscalYearOf('2026-10')).toBe(2026);
        expect(fiscalYearOf('2027-03')).toBe(2026);
        expect(fiscalYearOf('2027-04')).toBe(2027);
    });

    it('labels the FY', () => {
        expect(fiscalYearTitle(2026)).toBe('FY 2026-27');
    });

    it('shifts months across years', () => {
        expect(addMonthsToValue('2026-12', 1)).toBe('2027-01');
        expect(addMonthsToValue('2026-10', -12)).toBe('2025-10');
    });
});
