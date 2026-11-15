import { describe, expect, it } from 'vite-plus/test';
import { pageSummary, paginationRange } from './pagination-range';

const E = 'ellipsis';

describe('paginationRange', () => {
    it('returns nothing for zero pages', () => {
        expect(paginationRange({ page: 1, pageCount: 0 })).toEqual([]);
    });

    it('shows every page when the list is short', () => {
        expect(paginationRange({ page: 1, pageCount: 1 })).toEqual([1]);
        expect(paginationRange({ page: 3, pageCount: 5 })).toEqual([
            1, 2, 3, 4, 5,
        ]);
        expect(paginationRange({ page: 7, pageCount: 7 })).toEqual([
            1, 2, 3, 4, 5, 6, 7,
        ]);
    });

    it('matches the design: page 1 of 12', () => {
        expect(paginationRange({ page: 1, pageCount: 12 })).toEqual([
            1,
            2,
            3,
            E,
            12,
        ]);
    });

    it.each([
        [2, [1, 2, 3, E, 12]],
        [3, [1, 2, 3, 4, E, 12]],
        [4, [1, 2, 3, 4, 5, E, 12]],
        [5, [1, E, 4, 5, 6, E, 12]],
        [6, [1, E, 5, 6, 7, E, 12]],
        [9, [1, E, 8, 9, 10, 11, 12]],
        [10, [1, E, 9, 10, 11, 12]],
        [11, [1, E, 10, 11, 12]],
        [12, [1, E, 10, 11, 12]],
    ])('page %i of 12', (page, expected) => {
        expect(paginationRange({ page, pageCount: 12 })).toEqual(expected);
    });

    it('fills a one-page gap instead of an ellipsis', () => {
        expect(paginationRange({ page: 4, pageCount: 8 })).toEqual([
            1,
            2,
            3,
            4,
            5,
            E,
            8,
        ]);
        expect(paginationRange({ page: 5, pageCount: 8 })).toEqual([
            1,
            E,
            4,
            5,
            6,
            7,
            8,
        ]);
    });

    it('clamps an out-of-range page', () => {
        expect(paginationRange({ page: 0, pageCount: 12 })).toEqual([
            1,
            2,
            3,
            E,
            12,
        ]);
        expect(paginationRange({ page: 99, pageCount: 12 })).toEqual([
            1,
            E,
            10,
            11,
            12,
        ]);
    });

    it('widens the window with more siblings', () => {
        expect(
            paginationRange({ page: 10, pageCount: 20, siblings: 2 }),
        ).toEqual([1, E, 8, 9, 10, 11, 12, E, 20]);
    });

    it('never repeats a page and stays ascending', () => {
        for (let count = 1; count <= 30; count++) {
            for (let page = 1; page <= count; page++) {
                const numbers = paginationRange({
                    page,
                    pageCount: count,
                }).filter((item): item is number => item !== E);

                expect(numbers).toContain(page);
                expect(numbers[0]).toBe(1);
                expect(numbers.at(-1)).toBe(count);
                expect([...numbers].sort((a, b) => a - b)).toEqual(numbers);
                expect(new Set(numbers).size).toBe(numbers.length);
            }
        }
    });
});

describe('pageSummary', () => {
    it('describes the visible range', () => {
        expect(
            pageSummary({ page: 1, perPage: 10, total: 24, noun: 'expenses' }),
        ).toBe('Showing 1–10 of 24 expenses');
        expect(
            pageSummary({ page: 3, perPage: 10, total: 24, noun: 'expenses' }),
        ).toBe('Showing 21–24 of 24 expenses');
    });

    it('groups large totals the Indian way', () => {
        expect(
            pageSummary({
                page: 2,
                perPage: 50,
                total: 123456,
                noun: 'entries',
            }),
        ).toBe('Showing 51–100 of 1,23,456 entries');
    });

    it('says when there is nothing', () => {
        expect(
            pageSummary({ page: 1, perPage: 10, total: 0, noun: 'expenses' }),
        ).toBe('No expenses');
    });
});
