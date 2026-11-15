/*
 * Pure sorting helpers for DataTable. No React: unit-tested in table-sort.test.ts.
 */

export type SortDirection = 'ascending' | 'descending';

export type SortState = { key: string; direction: SortDirection };

/** What a column sorts by: text, a number (paise, hours) or a date. */
export type SortValue = string | number | Date | null | undefined;

const collator = new Intl.Collator('en-IN', {
    numeric: true,
    sensitivity: 'base',
});

/** "YYYY-MM-DD…" strings sort as dates; everything else as text. */
const ISO_DATE = /^\d{4}-\d{2}-\d{2}/;

function rank(value: SortValue): number | string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (value instanceof Date) {
        const time = value.getTime();

        return Number.isNaN(time) ? null : time;
    }

    if (typeof value === 'number') {
        return Number.isNaN(value) ? null : value;
    }

    if (ISO_DATE.test(value)) {
        const time = Date.parse(value);

        return Number.isNaN(time) ? value : time;
    }

    return value;
}

/**
 * Compare two sort values ascending. Numbers and dates numerically, text with a
 * natural, case-insensitive collation ("Item 2" before "Item 10"). Empty values
 * always sort last, whatever the direction is (see sortRows).
 */
export function compareValues(a: SortValue, b: SortValue): number {
    const ra = rank(a);
    const rb = rank(b);

    if (ra === null || rb === null) {
        return ra === rb ? 0 : ra === null ? 1 : -1;
    }

    if (typeof ra === 'number' && typeof rb === 'number') {
        return ra - rb;
    }

    return collator.compare(String(ra), String(rb));
}

/**
 * A sorted copy of `rows` (stable). `valueOf` returns the value of the sorted
 * column for a row; empty values stay at the bottom in both directions.
 */
export function sortRows<Row>(
    rows: readonly Row[],
    direction: SortDirection,
    valueOf: (row: Row) => SortValue,
): Row[] {
    const factor = direction === 'ascending' ? 1 : -1;

    return rows
        .map((row, index) => ({ row, index, value: valueOf(row) }))
        .sort((a, b) => {
            const aEmpty = rank(a.value) === null;
            const bEmpty = rank(b.value) === null;

            if (aEmpty || bEmpty) {
                return aEmpty === bEmpty ? a.index - b.index : aEmpty ? 1 : -1;
            }

            return (
                compareValues(a.value, b.value) * factor || a.index - b.index
            );
        })
        .map((entry) => entry.row);
}

/**
 * The sort after clicking a column header: a new column starts at its first
 * direction (ascending unless told otherwise); the same column flips.
 */
export function nextSort(
    current: SortState | null,
    key: string,
    firstDirection: SortDirection = 'ascending',
): SortState {
    if (current?.key === key) {
        return {
            key,
            direction:
                current.direction === 'ascending' ? 'descending' : 'ascending',
        };
    }

    return { key, direction: firstDirection };
}
