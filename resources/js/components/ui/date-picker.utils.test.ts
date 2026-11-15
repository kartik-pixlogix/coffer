import { describe, expect, it } from 'vite-plus/test';
import { parseDateLenient } from '@/lib/format';
import {
    addDays,
    addMonths,
    buildCalendarGrid,
    isDateAllowed,
    moveFocus,
    parseRangeText,
    toWeeks,
} from './date-picker.utils';

describe('buildCalendarGrid', () => {
    const october = buildCalendarGrid({
        year: 2026,
        month: 9,
        today: '2026-11-15',
        selected: '2026-10-22',
    });

    it('starts on Monday with the tail of the previous month', () => {
        // 1 Oct 2026 is a Thursday: Mon 28, Tue 29, Wed 30 Sep lead in.
        expect(october.slice(0, 4).map((d) => [d.day, d.inMonth])).toEqual([
            [28, false],
            [29, false],
            [30, false],
            [1, true],
        ]);
    });

    it('fills whole weeks only as far as the month needs', () => {
        expect(october).toHaveLength(35);
        expect(toWeeks(october)).toHaveLength(5);
        expect(october.at(-1)).toMatchObject({
            iso: '2026-11-01',
            inMonth: false,
        });
    });

    it('marks the selected day', () => {
        expect(october.filter((d) => d.isSelected).map((d) => d.iso)).toEqual([
            '2026-10-22',
        ]);
    });

    it('needs six rows when the month starts late in the week', () => {
        // 1 Aug 2026 is a Saturday.
        expect(
            buildCalendarGrid({ year: 2026, month: 7, today: '' }),
        ).toHaveLength(42);
    });

    it('fits February in four rows when it starts on a Monday', () => {
        // 1 Feb 2027 is a Monday, and 2027 isn't a leap year.
        expect(
            buildCalendarGrid({ year: 2027, month: 1, today: '' }),
        ).toHaveLength(28);
    });

    it('marks today, the range and unavailable days', () => {
        const november = buildCalendarGrid({
            year: 2026,
            month: 10,
            today: '2026-11-15',
            range: { start: '2026-11-10', end: '2026-11-13' },
            max: '2026-11-15',
            disabledDates: ['2026-11-02'],
        });
        const byIso = Object.fromEntries(november.map((d) => [d.iso, d]));

        expect(byIso['2026-11-15'].isToday).toBe(true);
        expect(byIso['2026-11-10'].isSelected).toBe(true);
        expect(byIso['2026-11-13'].isSelected).toBe(true);
        expect(byIso['2026-11-10'].inRange).toBe(false);
        expect(byIso['2026-11-11'].inRange).toBe(true);
        expect(byIso['2026-11-12'].inRange).toBe(true);
        expect(byIso['2026-11-16'].disabled).toBe(true);
        expect(byIso['2026-11-02'].disabled).toBe(true);
        expect(byIso['2026-11-15'].disabled).toBe(false);
    });
});

describe('isDateAllowed', () => {
    it('applies min, max and a custom rule', () => {
        const rules = {
            min: '2026-04-01',
            max: '2027-03-31',
            isDateDisabled: (iso: string) => iso === '2026-08-15',
        };

        expect(isDateAllowed('2026-03-31', rules)).toBe(false);
        expect(isDateAllowed('2026-04-01', rules)).toBe(true);
        expect(isDateAllowed('2027-04-01', rules)).toBe(false);
        expect(isDateAllowed('2026-08-15', rules)).toBe(false);
    });
});

describe('date arithmetic', () => {
    it('adds days across months and years', () => {
        expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
        expect(addDays('2027-01-01', -1)).toBe('2026-12-31');
    });

    it('adds months, clamping the day', () => {
        expect(addMonths('2027-01-31', 1)).toBe('2027-02-28');
        expect(addMonths('2026-10-22', -1)).toBe('2026-09-22');
        expect(addMonths('2026-10-22', 12)).toBe('2027-10-22');
    });
});

describe('moveFocus', () => {
    it.each([
        ['ArrowLeft', false, '2026-10-21'],
        ['ArrowRight', false, '2026-10-23'],
        ['ArrowUp', false, '2026-10-15'],
        ['ArrowDown', false, '2026-10-29'],
        ['PageUp', false, '2026-09-22'],
        ['PageDown', false, '2026-11-22'],
        ['PageDown', true, '2027-10-22'],
        ['Home', false, '2026-10-19'], // Monday
        ['End', false, '2026-10-25'], // Sunday
    ])('%s (shift %s) from 22 Oct → %s', (key, shift, iso) => {
        expect(moveFocus('2026-10-22', key, shift)).toBe(iso);
    });

    it('ignores other keys', () => {
        expect(moveFocus('2026-10-22', 'a')).toBeNull();
    });
});

describe('parseRangeText', () => {
    it('parses both ends leniently', () => {
        expect(
            parseRangeText('1 Apr 2026 – 31 Mar 2027', parseDateLenient),
        ).toEqual({ start: '2026-04-01', end: '2027-03-31' });
        expect(
            parseRangeText('01/04/26 to 31/03/27', parseDateLenient),
        ).toEqual({ start: '2026-04-01', end: '2027-03-31' });
    });

    it('rejects a reversed or broken range', () => {
        expect(
            parseRangeText('31 Mar 2027 – 1 Apr 2026', parseDateLenient),
        ).toBeNull();
        expect(parseRangeText('1 Apr 2026', parseDateLenient)).toBeNull();
        expect(
            parseRangeText('1 Apr 2026 – soon', parseDateLenient),
        ).toBeNull();
    });
});
