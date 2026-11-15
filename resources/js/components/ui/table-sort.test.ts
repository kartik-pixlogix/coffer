import { describe, expect, it } from 'vite-plus/test';
import { compareValues, nextSort, sortRows } from './table-sort';

type Expense = { title: string; amount: number | null; date: string };

const rows: Expense[] = [
    { title: 'Salary — Rohan Desai', amount: 8000000, date: '2026-10-31' },
    { title: 'Meta ads — October', amount: 2500000, date: '2026-10-19' },
    { title: 'figma organisation seats', amount: 1420000, date: '2026-10-05' },
    { title: 'Team dinner — Diwali', amount: null, date: '2026-10-24' },
];

describe('compareValues', () => {
    it('compares numbers numerically', () => {
        expect(compareValues(2, 10)).toBeLessThan(0);
        expect(compareValues(-5, 3)).toBeLessThan(0);
    });

    it('compares text naturally and ignores case', () => {
        expect(compareValues('Item 2', 'Item 10')).toBeLessThan(0);
        expect(compareValues('apple', 'Banana')).toBeLessThan(0);
        expect(compareValues('Meta', 'meta')).toBe(0);
    });

    it('compares ISO dates and Date objects as dates', () => {
        expect(compareValues('2026-10-05', '2026-10-19')).toBeLessThan(0);
        expect(
            compareValues(new Date(2026, 9, 31), new Date(2026, 9, 1)),
        ).toBeGreaterThan(0);
    });

    it('puts empty values last', () => {
        expect(compareValues(null, 1)).toBeGreaterThan(0);
        expect(compareValues('', 'a')).toBeGreaterThan(0);
        expect(compareValues(undefined, null)).toBe(0);
    });
});

describe('sortRows', () => {
    it('sorts by amount descending with empty amounts last', () => {
        const sorted = sortRows(rows, 'descending', (r) => r.amount);

        expect(sorted.map((r) => r.amount)).toEqual([
            8000000,
            2500000,
            1420000,
            null,
        ]);
    });

    it('keeps empty values last when ascending too', () => {
        const sorted = sortRows(rows, 'ascending', (r) => r.amount);

        expect(sorted.map((r) => r.amount)).toEqual([
            1420000,
            2500000,
            8000000,
            null,
        ]);
    });

    it('sorts by date and by text', () => {
        expect(
            sortRows(rows, 'ascending', (r) => r.date).map((r) => r.date),
        ).toEqual(['2026-10-05', '2026-10-19', '2026-10-24', '2026-10-31']);
        expect(
            sortRows(rows, 'ascending', (r) => r.title).map((r) => r.title[0]),
        ).toEqual(['f', 'M', 'S', 'T']);
    });

    it('is stable and does not mutate the input', () => {
        const ties = [
            { id: 'a', v: 1 },
            { id: 'b', v: 1 },
            { id: 'c', v: 0 },
        ];
        const copy = [...ties];

        expect(
            sortRows(ties, 'descending', (r) => r.v).map((r) => r.id),
        ).toEqual(['a', 'b', 'c']);
        expect(ties).toEqual(copy);
    });
});

describe('nextSort', () => {
    it('starts a new column at its first direction', () => {
        expect(nextSort(null, 'date')).toEqual({
            key: 'date',
            direction: 'ascending',
        });
        expect(
            nextSort(
                { key: 'date', direction: 'ascending' },
                'amount',
                'descending',
            ),
        ).toEqual({ key: 'amount', direction: 'descending' });
    });

    it('flips the same column', () => {
        expect(
            nextSort({ key: 'amount', direction: 'descending' }, 'amount'),
        ).toEqual({
            key: 'amount',
            direction: 'ascending',
        });
        expect(
            nextSort({ key: 'amount', direction: 'ascending' }, 'amount'),
        ).toEqual({
            key: 'amount',
            direction: 'descending',
        });
    });
});
