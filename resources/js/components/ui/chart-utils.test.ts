import { describe, expect, it } from 'vite-plus/test';
import {
    BRAND_RAMP,
    PARTNER_PALETTE,
    angleFromTop,
    areaGeometry,
    areaPath,
    barHeight,
    donutGradient,
    donutSegments,
    formatBarChip,
    linearScale,
    maxAbs,
    monthLabels,
    nearestIndex,
    niceScale,
    ringOffset,
    segmentAt,
    smoothPath,
    HEALTH_RING_LENGTH,
    TOTP_RING_LENGTH,
} from './chart-utils';

const L = 1_00_000_00; // ₹1 lakh in paise

/** Design sample (Main.dc.html), Apr–Oct 2026, in paise. */
const income = [620000, 740000, 810000, 975000, 830000, 1085000, 1150000].map(
    (r) => r * 100,
);
const expense = [685000, 590000, 640000, 610000, 862650, 430000, 550000].map(
    (r) => r * 100,
);

describe('linearScale', () => {
    it('maps a domain onto a range, inverted for y', () => {
        const y = linearScale([0, 100], [160, 14]);

        expect(y(0)).toBe(160);
        expect(y(100)).toBe(14);
        expect(y(50)).toBe(87);
    });

    it('maps a zero-width domain to the range start', () => {
        expect(linearScale([5, 5], [0, 10])(5)).toBe(0);
    });
});

describe('niceScale', () => {
    it('gives the design axis ₹12L ₹8L ₹4L ₹0 for revenue up to ₹11.5L', () => {
        const scale = niceScale([...income, ...expense]);

        expect(scale.ticks).toEqual([12 * L, 8 * L, 4 * L, 0]);
        expect(scale).toMatchObject({ min: 0, max: 12 * L, step: 4 * L });
    });

    it('always includes zero and covers the data', () => {
        const scale = niceScale([3 * L, 7.3 * L]);

        expect(scale.min).toBe(0);
        expect(scale.max).toBeGreaterThanOrEqual(7.3 * L);
        expect(scale.ticks.at(-1)).toBe(0);
    });

    it('extends below zero for losses, on step multiples', () => {
        const scale = niceScale([-65000 * 100, 655000 * 100]);

        expect(scale.min).toBeLessThan(0);
        expect(scale.max).toBeGreaterThanOrEqual(655000 * 100);
        expect(scale.ticks).toContain(0);
        expect(Math.abs(scale.min % scale.step)).toBe(0);
        expect(scale.ticks).toHaveLength(4);
    });

    it('returns integer paise ticks', () => {
        for (const tick of niceScale([123457]).ticks) {
            expect(Number.isInteger(tick)).toBe(true);
        }
    });

    it('keeps an all-zero series drawable', () => {
        const scale = niceScale([0, 0]);

        expect(scale.max).toBeGreaterThan(0);
        expect(scale.ticks.at(-1)).toBe(0);
    });
});

describe('smoothPath', () => {
    it('reproduces the design revenue line exactly', () => {
        const { xs, y } = areaGeometry(7, 12 * L, 520, 160);
        const d = smoothPath(income.map((v, i) => [xs[i], y(v)]));

        expect(d).toBe(
            'M0.0 84.6C14.4 82.1 57.8 73.8 86.7 70.0C115.6 66.1 144.4 66.2 173.3 61.4C202.2 56.7 231.1 41.8 260.0 41.4C288.9 41.0 317.8 61.2 346.7 59.0C375.6 56.8 404.4 34.5 433.3 28.0C462.2 21.5 505.6 21.4 520.0 20.1',
        );
    });

    it('reproduces the design expenses line exactly', () => {
        const { xs, y } = areaGeometry(7, 12 * L, 520, 160);
        const d = smoothPath(expense.map((v, i) => [xs[i], y(v)]));

        expect(d).toBe(
            'M0.0 76.7C14.4 78.6 57.8 87.3 86.7 88.2C115.6 89.1 144.4 82.5 173.3 82.1C202.2 81.7 231.1 90.3 260.0 85.8C288.9 81.3 317.8 51.4 346.7 55.0C375.6 58.7 404.4 101.3 433.3 107.7C462.2 114.0 505.6 95.5 520.0 93.1',
        );
    });

    it('handles empty and single-point series', () => {
        expect(smoothPath([])).toBe('');
        expect(smoothPath([[10, 20]])).toBe('M10.0 20.0');
    });

    it('closes the area to the baseline', () => {
        expect(areaPath('M0 1C1 1 2 2 3 3', 520, 160)).toBe(
            'M0 1C1 1 2 2 3 3L520 160L0 160Z',
        );
        expect(areaPath('', 520, 160)).toBe('');
    });
});

describe('areaGeometry / nearestIndex', () => {
    it('spreads points edge to edge', () => {
        expect(areaGeometry(3, 100, 520, 160).xs).toEqual([0, 260, 520]);
        expect(areaGeometry(1, 100, 520, 160).xs).toEqual([260]);
    });

    it('snaps a pointer fraction to the nearest point', () => {
        expect(nearestIndex(0, 7)).toBe(0);
        expect(nearestIndex(0.83, 7)).toBe(5);
        expect(nearestIndex(0.92, 7)).toBe(6);
        expect(nearestIndex(-1, 7)).toBe(0);
        expect(nearestIndex(2, 7)).toBe(6);
        expect(nearestIndex(0.5, 1)).toBe(0);
    });
});

describe('bars', () => {
    const profit = income.map((v, i) => v - expense[i]);

    it('scales heights to the largest |value| like the preview', () => {
        const top = maxAbs(profit);
        const heights = profit.map((p) => barHeight(p, top, 100));

        // Apr −65,000 · May 1,50,000 · Jun 1,70,000 · Jul 3,65,000 · Aug −32,650 · Sep 6,55,000 · Oct 6,00,000
        expect(heights).toEqual([10, 23, 26, 56, 6, 100, 92]);
    });

    it('keeps tiny bars visible and copes with all zero', () => {
        expect(barHeight(1, 1000, 100)).toBe(6);
        expect(barHeight(0, 0, 100)).toBe(6);
        expect(maxAbs([])).toBe(0);
    });

    it('writes value chips like the preview', () => {
        expect(formatBarChip(600000 * 100)).toBe('₹6.0L');
        expect(formatBarChip(-32650 * 100)).toBe('−₹33K');
        expect(formatBarChip(1_20_00_000 * 100)).toBe('₹1.2Cr');
        expect(formatBarChip(95000)).toBe('₹950');
    });
});

describe('donut', () => {
    const partners = [
        { name: 'Aarav Mehta', value: 2136240_00 },
        { name: 'Ishita Rao', value: 1266840_00 },
        { name: 'Kabir Shah', value: 1763640_00 },
        { name: 'Meera Iyer', value: 1043280_00 },
    ];

    it('gives each slice its partner colour and rounded percent', () => {
        const segments = donutSegments(partners, PARTNER_PALETTE);

        expect(segments.map((s) => s.percent)).toEqual([34, 20, 28, 17]);
        expect(segments.map((s) => s.color)).toEqual(
            PARTNER_PALETTE.slice(0, 4),
        );
        expect(segments[2].share).toBeCloseTo(28.4, 1);
        expect(segments.at(-1)?.endDeg).toBeCloseTo(360);
    });

    it('matches the category split of the preview', () => {
        const segments = donutSegments(
            [
                { name: 'Salary', value: 2840000 },
                { name: 'Software', value: 412650 },
                { name: 'Hardware', value: 385000 },
                { name: 'Marketing', value: 320000 },
                { name: 'Travel & office', value: 410000 },
            ],
            BRAND_RAMP,
        );

        expect(segments.map((s) => s.percent)).toEqual([65, 9, 9, 7, 9]);
    });

    it('folds slices beyond the palette into Other and drops zeros', () => {
        const segments = donutSegments(
            [
                { name: 'A', value: 5 },
                { name: 'Zero', value: 0 },
                { name: 'B', value: 4 },
                { name: 'C', value: 3 },
                { name: 'D', value: 2 },
                { name: 'E', value: 1 },
                { name: 'F', value: 1 },
            ],
            BRAND_RAMP,
        );

        expect(segments.map((s) => s.name)).toEqual([
            'A',
            'B',
            'C',
            'D',
            'Other',
        ]);
        expect(segments.at(-1)?.value).toBe(2);
    });

    it('keeps an explicit colour', () => {
        const [segment] = donutSegments(
            [{ name: 'Kabir', value: 1, color: 'var(--partner-3)' }],
            PARTNER_PALETTE,
        );

        expect(segment.color).toBe('var(--partner-3)');
    });

    it('builds a conic gradient with 1.2° surface-card gaps', () => {
        const gradient = donutGradient(
            donutSegments(
                [
                    { name: 'A', value: 1 },
                    { name: 'B', value: 1 },
                ],
                PARTNER_PALETTE,
            ),
        );

        expect(gradient).toBe(
            'conic-gradient(var(--partner-1) 0.00deg 178.80deg, var(--surface-card) 178.80deg 180.00deg, var(--partner-2) 180.00deg 358.80deg, var(--surface-card) 358.80deg 360.00deg)',
        );
        expect(donutGradient([])).toBe('var(--surface-muted)');
    });

    it('finds the slice under the pointer, clockwise from the top', () => {
        expect(angleFromTop(0, -10)).toBeCloseTo(0);
        expect(angleFromTop(10, 0)).toBeCloseTo(90);
        expect(angleFromTop(0, 10)).toBeCloseTo(180);
        expect(angleFromTop(-10, 0)).toBeCloseTo(270);

        const segments = donutSegments(partners, PARTNER_PALETTE);

        expect(segmentAt(segments, 10)).toBe(0);
        expect(segmentAt(segments, 250)).toBe(2);
        expect(segmentAt(segments, 359)).toBe(3);
        expect(segmentAt([], 10)).toBe(-1);
    });
});

describe('labels and rings', () => {
    it('labels a month for the axis and the tooltip', () => {
        expect(monthLabels('2026-09-01')).toEqual({
            short: 'Sep',
            period: 'Sep 2026',
        });
    });

    it('computes ring offsets like the previews', () => {
        expect(ringOffset(HEALTH_RING_LENGTH, 0.82)).toBe(29.4);
        expect(ringOffset(HEALTH_RING_LENGTH, 0.54)).toBe(75.15);
        expect(ringOffset(TOTP_RING_LENGTH, 18 / 30)).toBe(50.26);
        expect(ringOffset(TOTP_RING_LENGTH, 24 / 30)).toBe(25.13);
        expect(ringOffset(TOTP_RING_LENGTH, 2)).toBe(0);
        expect(ringOffset(TOTP_RING_LENGTH, -1)).toBe(TOTP_RING_LENGTH);
    });
});
