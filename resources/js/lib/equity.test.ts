import { describe, expect, it } from 'vite-plus/test';
import {
    evenSplit,
    formatEquity,
    formatEquityShort,
    gapMessage,
    isWhole,
    overallEquity,
    parseEquity,
    totalEquity,
} from './equity';

describe('parseEquity', () => {
    it('reads percentages to two decimals', () => {
        expect(parseEquity('60')).toBe(6000);
        expect(parseEquity('60.5')).toBe(6050);
        expect(parseEquity('33.33 %')).toBe(3333);
        expect(parseEquity('100.00')).toBe(10000);
        expect(parseEquity('0')).toBe(0);
    });

    it('refuses what it can’t store exactly', () => {
        expect(parseEquity('')).toBeNull();
        expect(parseEquity('33.333')).toBeNull();
        expect(parseEquity('100.01')).toBeNull();
        expect(parseEquity('1.2.3')).toBeNull();
    });
});

describe('formatting', () => {
    it('shows two decimals, or the short form', () => {
        expect(formatEquity(6000)).toBe('60.00');
        expect(formatEquity(5)).toBe('0.05');
        expect(formatEquityShort(6000)).toBe('60%');
        expect(formatEquityShort(3333)).toBe('33.33%');
        expect(formatEquityShort(3350)).toBe('33.5%');
    });
});

describe('totals', () => {
    it('is whole only at exactly 100%', () => {
        expect(isWhole(['60.00', '40.00'])).toBe(true);
        expect(isWhole(['60.00', '35.00'])).toBe(false);
        expect(isWhole(['100', 'abc'])).toBe(false);
        expect(isWhole([])).toBe(false);
        expect(totalEquity(['60', '35'])).toBe(9500);
    });

    it('says how to fix the gap, in the design’s words', () => {
        expect(gapMessage(9500)).toBe(
            'Equity adds up to 95.00%. Add 5.00% so it totals 100%.',
        );
        expect(gapMessage(10500)).toBe(
            'Equity adds up to 105.00%. Remove 5.00% so it totals 100%.',
        );
        expect(
            gapMessage(9000, 'Vertex Labs partners', 'this side totals 100%'),
        ).toBe(
            'Vertex Labs partners add up to 90.00%. Add 10.00% so this side totals 100%.',
        );
    });
});

describe('evenSplit', () => {
    it('puts the rounding remainder on the first row', () => {
        expect(evenSplit(2)).toEqual([5000, 5000]);
        expect(evenSplit(3)).toEqual([3334, 3333, 3333]);
        expect(evenSplit(7).reduce((a, b) => a + b, 0)).toBe(10000);
        expect(evenSplit(0)).toEqual([]);
    });
});

describe('overallEquity', () => {
    it('multiplies the side share by the share within it', () => {
        expect(overallEquity(5000, 6000)).toBe(3000);
        expect(overallEquity(5000, 5000)).toBe(2500);
    });
});
