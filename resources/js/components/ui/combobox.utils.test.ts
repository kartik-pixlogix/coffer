import { describe, expect, it } from 'vite-plus/test';
import {
    filterOptions,
    highlightMatch,
    nextEnabledIndex,
} from './combobox.utils';

const PEOPLE = [
    { label: 'Kabir Shah', meta: 'Bluefin Infotech' },
    { label: 'Sana Qureshi', meta: 'shared with Kabir' },
    { label: 'Neha Joshi', meta: 'inactive' },
    { label: 'Aarav Mehta', keywords: ['aarav@vertexlabs.in'] },
];

describe('highlightMatch', () => {
    it('marks the first match and keeps the original casing', () => {
        expect(highlightMatch('Kabir Shah', 'sha')).toEqual([
            { text: 'Kabir ', match: false },
            { text: 'Sha', match: true },
            { text: 'h', match: false },
        ]);
    });

    it('handles a match at the start', () => {
        expect(highlightMatch('shared with Kabir', 'SHA')).toEqual([
            { text: 'sha', match: true },
            { text: 'red with Kabir', match: false },
        ]);
    });

    it('returns the plain text without a match or query', () => {
        expect(highlightMatch('Neha Joshi', 'xyz')).toEqual([
            { text: 'Neha Joshi', match: false },
        ]);
        expect(highlightMatch('Neha Joshi', '  ')).toEqual([
            { text: 'Neha Joshi', match: false },
        ]);
    });
});

describe('filterOptions', () => {
    it('returns everything for an empty query', () => {
        expect(filterOptions(PEOPLE, '')).toHaveLength(4);
    });

    it('matches labels and meta, in the original order', () => {
        const results = filterOptions(PEOPLE, 'sha');

        expect(results.map((r) => r.option.label)).toEqual([
            'Kabir Shah',
            'Sana Qureshi',
        ]);
        expect(results.map((r) => r.index)).toEqual([0, 1]);
    });

    it('highlights the label first, else the meta', () => {
        const [kabir, sana] = filterOptions(PEOPLE, 'sha');

        expect(kabir.label.some((s) => s.match)).toBe(true);
        expect(kabir.meta?.some((s) => s.match)).toBe(false);
        expect(sana.label.some((s) => s.match)).toBe(false);
        expect(sana.meta?.[0]).toEqual({ text: 'sha', match: true });
    });

    it('matches hidden keywords without highlighting', () => {
        const results = filterOptions(PEOPLE, 'vertexlabs');

        expect(results.map((r) => r.option.label)).toEqual(['Aarav Mehta']);
        expect(results[0].meta).toBeNull();
    });

    it('returns nothing when no option matches', () => {
        expect(filterOptions(PEOPLE, 'Saarthi mobile')).toEqual([]);
    });
});

describe('nextEnabledIndex', () => {
    const disabled = [false, true, false];

    it('skips disabled options and wraps', () => {
        expect(nextEnabledIndex(disabled, 0, 1)).toBe(2);
        expect(nextEnabledIndex(disabled, 2, 1)).toBe(0);
        expect(nextEnabledIndex(disabled, 0, -1)).toBe(2);
    });

    it('starts at the top going down and the bottom going up', () => {
        expect(nextEnabledIndex(disabled, -1, 1)).toBe(0);
        expect(nextEnabledIndex(disabled, -1, -1)).toBe(2);
    });

    it('returns -1 when everything is disabled', () => {
        expect(nextEnabledIndex([true, true], -1, 1)).toBe(-1);
        expect(nextEnabledIndex([], -1, 1)).toBe(-1);
    });
});
