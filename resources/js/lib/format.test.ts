import { describe, expect, it } from 'vite-plus/test';
import {
    MINUS,
    fiscalYearLabel,
    fiscalYearStart,
    formatDate,
    formatHours,
    formatInr,
    formatInrCompact,
    formatLongDate,
    formatMonth,
    formatPercent,
    formatRupeeInput,
    parseDateLenient,
    parseRupeesToPaise,
    toIsoDate,
} from './format';

describe('formatInr', () => {
    it.each([
        [0, '₹0'],
        [100, '₹1'],
        [99_900, '₹999'],
        [1_00_000, '₹1,000'],
        [12_34_567_00, '₹12,34,567'],
        [34_61_915_00, '₹34,61,915'],
        [1_00_00_00_000, '₹1,00,00,000'],
        [123_450, '₹1,234.50'],
        [5, '₹0.05'],
    ])('formats %i paise as %s', (paise, expected) => {
        expect(formatInr(paise)).toBe(expected);
    });

    it('uses a true minus for negatives', () => {
        expect(formatInr(-2_66_500_00)).toBe(`${MINUS}₹2,66,500`);
    });

    it('supports signed, symbol-less and decimal modes', () => {
        expect(formatInr(5_100_00, { signed: true })).toBe('+₹5,100');
        expect(formatInr(1_14_900_00, { symbol: false })).toBe('1,14,900');
        expect(formatInr(100, { decimals: 'always' })).toBe('₹1.00');
        expect(formatInr(150, { decimals: 'never' })).toBe('₹2');
        expect(formatInr(149, { decimals: 'never' })).toBe('₹1');
    });

    it('rejects non-integer paise', () => {
        expect(() => formatInr(1.5)).toThrow(TypeError);
    });
});

describe('formatInrCompact', () => {
    it.each([
        [950_00, '₹950'],
        [45_000_00, '₹45K'],
        [2_66_500_00, '₹2.7L'],
        [62_10_000_00, '₹62.1L'],
        [1_20_00_000_00, '₹1.2Cr'],
        [12_00_000_00, '₹12L'],
        [-3_24_600_00, `${MINUS}₹3.2L`],
        [0, '₹0'],
    ])('formats %i paise as %s', (paise, expected) => {
        expect(formatInrCompact(paise)).toBe(expected);
    });
});

describe('parseRupeesToPaise', () => {
    it.each([
        ['1,14,900', 1_14_900_00],
        ['₹ 1,14,900', 1_14_900_00],
        ['1234.5', 123_450],
        ['1234.56', 123_456],
        ['0.05', 5],
        ['1234.', 123_400],
    ])('parses %s', (input, expected) => {
        expect(parseRupeesToPaise(input)).toBe(expected);
    });

    it.each(['', 'abc', '-500', '1.234', '1,2.3.4'])('rejects %s', (input) => {
        expect(parseRupeesToPaise(input)).toBeNull();
    });
});

describe('formatRupeeInput', () => {
    it.each([
        ['1234567', '12,34,567'],
        ['12,34,567', '12,34,567'],
        ['1234.', '1,234.'],
        ['1234.567', '1,234.56'],
        ['.5', '0.5'],
        ['00012', '12'],
        ['12a3', '123'],
        ['1.2.3', '1.23'],
        ['', ''],
    ])('re-groups %s as %s', (input, expected) => {
        expect(formatRupeeInput(input)).toBe(expected);
    });
});

describe('dates', () => {
    it('formats house dates', () => {
        expect(formatDate('2026-10-22')).toBe('22 Oct 2026');
        expect(formatDate(new Date(2026, 3, 1))).toBe('1 Apr 2026');
        expect(formatMonth('2026-10-05')).toBe('Oct 2026');
        expect(formatLongDate(new Date(2026, 10, 15))).toBe(
            'Sunday, 15 Nov 2026',
        );
        expect(toIsoDate(new Date(2026, 0, 9))).toBe('2026-01-09');
    });

    it.each([
        ['2026-04-01', '2026-04-01'],
        ['01/04/2026', '2026-04-01'],
        ['1/4/26', '2026-04-01'],
        ['01-04-2026', '2026-04-01'],
        ['1-Apr-26', '2026-04-01'],
        ['1 Apr 2026', '2026-04-01'],
        ['31-october-2026', '2026-10-31'],
    ])('parses %s leniently', (input, expected) => {
        expect(parseDateLenient(input)).toBe(expected);
    });

    it.each(['31/02/2026', '2026-13-01', 'yesterday', '1-Foo-26', ''])(
        'rejects %s',
        (input) => {
            expect(parseDateLenient(input)).toBeNull();
        },
    );

    it('labels fiscal years from April by default', () => {
        expect(fiscalYearLabel(new Date(2026, 3, 1))).toBe('FY 2026-27');
        expect(fiscalYearLabel(new Date(2027, 2, 31))).toBe('FY 2026-27');
        expect(fiscalYearLabel(new Date(2026, 2, 31))).toBe('FY 2025-26');
        expect(fiscalYearLabel(new Date(2099, 5, 1))).toBe('FY 2099-00');
        expect(fiscalYearLabel(new Date(2026, 5, 1), 1)).toBe('FY 2026');
        expect(toIsoDate(fiscalYearStart(new Date(2026, 8, 1), 10))).toBe(
            '2025-10-01',
        );
    });
});

describe('hours and percent', () => {
    it('formats hours to one decimal', () => {
        expect(formatHours(2157.5)).toBe('2,157.5');
        expect(formatHours(2.73)).toBe('2.7');
        expect(formatHours(8)).toBe('8.0');
    });

    it('formats percentages', () => {
        expect(formatPercent(12.5)).toBe('12.5%');
        expect(formatPercent(6)).toBe('6%');
        expect(formatPercent(-8.25, 1)).toBe(`${MINUS}8.3%`);
    });
});

describe('number format (spec §17)', () => {
    it('groups by the viewer’s choice, Indian by default', async () => {
        const {
            setNumberFormat,
            formatInr,
            formatInrCompact,
            formatIndianNumber,
        } = await import('./format');

        expect(formatInr(18_42_350_00)).toBe('₹18,42,350');

        setNumberFormat('international');
        expect(formatInr(18_42_350_00)).toBe('₹1,842,350');
        expect(formatIndianNumber(1234567.5, 1)).toBe('1,234,567.5');
        expect(formatInrCompact(18_42_350_00)).toBe('₹1.8M');
        expect(formatInrCompact(45_000_00)).toBe('₹45K');

        setNumberFormat('indian');
        expect(formatInrCompact(18_42_350_00)).toBe('₹18.4L');
    });
});
