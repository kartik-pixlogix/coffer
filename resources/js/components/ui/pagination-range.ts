import { formatIndianNumber } from '@/lib/format';

export type PageItem = number | 'ellipsis';

export type PaginationRangeOptions = {
    /** Current page, 1-based (clamped into range). */
    page: number;
    pageCount: number;
    /** Pages shown either side of the current one (default 1). */
    siblings?: number;
};

/**
 * The page buttons to show: first and last page, a window of `2 × siblings + 1` pages
 * around the current one (slid inward at the ends so it keeps its width), and an
 * ellipsis for any run of two or more hidden pages. A gap of exactly one page shows
 * that page instead. Short lists (up to `2 × siblings + 5` pages) show every page.
 *
 *   page 1 of 12  → 1 2 3 … 12
 *   page 6 of 12  → 1 … 5 6 7 … 12
 *   page 12 of 12 → 1 … 10 11 12
 */
export function paginationRange({
    page,
    pageCount,
    siblings = 1,
}: PaginationRangeOptions): PageItem[] {
    const count = Math.max(0, Math.floor(pageCount));
    const span = Math.max(0, Math.floor(siblings));

    if (count === 0) {
        return [];
    }

    if (count <= 2 * span + 5) {
        return Array.from({ length: count }, (_, i) => i + 1);
    }

    const current = Math.min(Math.max(1, Math.floor(page)), count);
    const width = 2 * span + 1;
    const start = Math.min(Math.max(1, current - span), count - width + 1);
    const end = start + width - 1;

    const shown = new Set<number>([1, count]);
    for (let p = start; p <= end; p++) {
        shown.add(p);
    }

    const pages = [...shown].sort((a, b) => a - b);
    const items: PageItem[] = [];

    pages.forEach((p, i) => {
        const previous = pages[i - 1];

        if (previous !== undefined) {
            if (p - previous === 2) {
                items.push(previous + 1);
            } else if (p - previous > 2) {
                items.push('ellipsis');
            }
        }

        items.push(p);
    });

    return items;
}

export type PageSummaryOptions = {
    page: number;
    perPage: number;
    total: number;
    /** Plural noun for the rows, e.g. "expenses". */
    noun: string;
};

/** "Showing 1–10 of 24 expenses" (en dash, Indian grouping); "No expenses" when empty. */
export function pageSummary({
    page,
    perPage,
    total,
    noun,
}: PageSummaryOptions): string {
    if (total <= 0) {
        return `No ${noun}`;
    }

    const from = Math.min((Math.max(1, page) - 1) * perPage + 1, total);
    const to = Math.min(from + perPage - 1, total);

    return `Showing ${formatIndianNumber(from)}–${formatIndianNumber(to)} of ${formatIndianNumber(total)} ${noun}`;
}
