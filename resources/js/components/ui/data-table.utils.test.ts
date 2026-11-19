import { describe, expect, it } from 'vitest';
import {
    NUMERIC_COLUMN_MIN,
    TEXT_COLUMN_MIN,
    columnTrack,
    tableMinWidth,
    trackMin,
} from '@/components/ui/data-table.utils';

describe('data table column sizing', () => {
    it('reads the minimum of a track', () => {
        expect(trackMin('120px')).toBe(120);
        expect(trackMin('minmax(180px,1.4fr)')).toBe(180);
        expect(trackMin('minmax(0,1fr)')).toBe(0);
        expect(trackMin('auto')).toBe(0);
    });

    it('raises zero minimums to a readable width', () => {
        expect(columnTrack(undefined, false)).toBe(
            `minmax(${TEXT_COLUMN_MIN}px,1fr)`,
        );
        expect(columnTrack(undefined, true)).toBe(
            `minmax(${NUMERIC_COLUMN_MIN}px,1fr)`,
        );
        expect(columnTrack('minmax(0,2fr)', false)).toBe(
            `minmax(${TEXT_COLUMN_MIN}px,2fr)`,
        );
        expect(columnTrack('minmax(200px,2fr)', false)).toBe(
            'minmax(200px,2fr)',
        );
        expect(columnTrack('116px', true)).toBe('116px');
    });

    it('sums the minimums, gaps and row padding', () => {
        expect(tableMinWidth(['28px', 'minmax(200px,1fr)', '116px'])).toBe(
            28 + 200 + 116 + 24 + 28,
        );
        expect(tableMinWidth([])).toBe(0);
    });
});
