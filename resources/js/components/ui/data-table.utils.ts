/*
 * Column sizing for the data table (D128). Every row is its own grid, so the table
 * needs a minimum width that holds every track's minimum; otherwise the cells spill
 * past the row's background (X36) instead of the table scrolling in its wrap.
 */

/** Smallest a text column gets: "Repaid to Aarav Mehta" wraps at most once. */
export const TEXT_COLUMN_MIN = 128;
/** Smallest a money / figure column gets. */
export const NUMERIC_COLUMN_MIN = 96;
/** `.cf-tr` gap and horizontal padding (bundle). */
export const ROW_GAP = 12;
export const ROW_PADDING_X = 14;

/** The minimum of one grid track in px ("120px", "minmax(180px,2fr)"); 0 when it has none. */
export function trackMin(track: string): number {
    const value = track.trim();
    const minmax = value.match(/^minmax\(\s*([\d.]+)px\s*,/);

    if (minmax) {
        return Number(minmax[1]);
    }

    const fixed = value.match(/^([\d.]+)px$/);

    return fixed ? Number(fixed[1]) : 0;
}

/**
 * The track for a column: its own width, with a zero minimum ("minmax(0,1fr)", or
 * none given) raised to a readable one so text never wraps one word per line.
 */
export function columnTrack(
    width: string | undefined,
    numeric: boolean,
): string {
    const min = numeric ? NUMERIC_COLUMN_MIN : TEXT_COLUMN_MIN;

    if (width === undefined) {
        return `minmax(${min}px,1fr)`;
    }

    return width.replace(/^minmax\(\s*0(px)?\s*,/, `minmax(${min}px,`);
}

/** The narrowest the table can be with every track at its minimum. */
export function tableMinWidth(tracks: readonly string[]): number {
    if (tracks.length === 0) {
        return 0;
    }

    return (
        tracks.reduce((sum, track) => sum + trackMin(track), 0) +
        ROW_GAP * (tracks.length - 1) +
        ROW_PADDING_X * 2
    );
}
