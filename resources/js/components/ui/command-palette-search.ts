/*
 * Filtering and ranking for the command palette (design-system CommandPalette).
 * Pure functions, no React, so they are unit-tested on their own.
 */

/** A [start, end) slice of a label to highlight. */
export type MatchRange = [start: number, end: number];

export type SearchableItem = {
    id: string;
    label: string;
    /** Secondary text after the label; searchable, never highlighted. */
    hint?: string;
    /** Extra words that find the item without showing ("settlement" for Record transfer). */
    keywords?: string[];
};

export type SearchableGroup<T extends SearchableItem> = {
    /** "Pages", "Actions", "People", "Vault". */
    label: string;
    items: T[];
};

export type RankedItem<T extends SearchableItem> = {
    item: T;
    score: number;
    /** Where the query matches the label, merged and sorted. */
    ranges: MatchRange[];
};

export type RankedGroup<T extends SearchableItem> = {
    label: string;
    items: RankedItem<T>[];
};

const SCORE = {
    exact: 100,
    labelPrefix: 60,
    wordPrefix: 40,
    labelSubstring: 20,
    hint: 8,
    keyword: 6,
} as const;

/** Lower-case and strip accents so "Saarthi" matches "saarthi" and "é" matches "e". */
export function normalise(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

export function tokenise(query: string): string[] {
    return normalise(query).split(/\s+/).filter(Boolean);
}

/** Index of the first word start in `text` where `token` begins, or -1. */
function wordPrefixIndex(text: string, token: string): number {
    let from = 0;

    while (from <= text.length - token.length) {
        const at = text.indexOf(token, from);

        if (at === -1) {
            return -1;
        }

        if (at === 0 || /[^a-z0-9]/.test(text[at - 1])) {
            return at;
        }

        from = at + 1;
    }

    return -1;
}

function mergeRanges(ranges: MatchRange[]): MatchRange[] {
    const sorted = [...ranges].sort((a, b) => a[0] - b[0]);
    const merged: MatchRange[] = [];

    for (const range of sorted) {
        const last = merged[merged.length - 1];

        if (last && range[0] <= last[1]) {
            last[1] = Math.max(last[1], range[1]);
        } else {
            merged.push([range[0], range[1]]);
        }
    }

    return merged;
}

/**
 * Score one item against the query tokens. Every token must match the label, the
 * hint or a keyword, or the item is out (null). Label matches rank above hint and
 * keyword matches; a label that starts with the query ranks highest.
 */
export function scoreItem(
    item: SearchableItem,
    tokens: string[],
): { score: number; ranges: MatchRange[] } | null {
    if (tokens.length === 0) {
        return { score: 0, ranges: [] };
    }

    const label = normalise(item.label);
    const hint = normalise(item.hint ?? '');
    const keywords = (item.keywords ?? []).map(normalise);
    const ranges: MatchRange[] = [];
    let score = 0;

    for (const token of tokens) {
        const prefix = wordPrefixIndex(label, token);
        const anywhere = prefix === -1 ? label.indexOf(token) : prefix;

        if (anywhere !== -1) {
            ranges.push([anywhere, anywhere + token.length]);
            score +=
                anywhere === 0
                    ? SCORE.labelPrefix
                    : prefix !== -1
                      ? SCORE.wordPrefix
                      : SCORE.labelSubstring;
            continue;
        }

        if (hint.includes(token)) {
            score += SCORE.hint;
            continue;
        }

        if (keywords.some((keyword) => keyword.includes(token))) {
            score += SCORE.keyword;
            continue;
        }

        return null;
    }

    if (label === tokens.join(' ')) {
        score += SCORE.exact;
    }

    return { score, ranges: mergeRanges(ranges) };
}

/**
 * Filter and rank grouped results. Groups keep their order and drop out when empty;
 * inside a group the best match comes first (ties keep the given order). An empty
 * query returns everything in order. `limit` caps the items per group.
 */
export function searchCommands<T extends SearchableItem>(
    groups: SearchableGroup<T>[],
    query: string,
    limit = 8,
): RankedGroup<T>[] {
    const tokens = tokenise(query);

    return groups
        .map((group) => {
            const ranked = group.items
                .map((item, index) => {
                    const match = scoreItem(item, tokens);

                    return match ? { item, index, ...match } : null;
                })
                .filter((entry) => entry !== null)
                .sort((a, b) => b.score - a.score || a.index - b.index)
                .slice(0, limit)
                .map(({ item, score, ranges }) => ({ item, score, ranges }));

            return { label: group.label, items: ranked };
        })
        .filter((group) => group.items.length > 0);
}

/** Split a label into plain and highlighted parts for rendering. */
export function highlightParts(
    label: string,
    ranges: MatchRange[],
): { text: string; match: boolean }[] {
    const parts: { text: string; match: boolean }[] = [];
    let at = 0;

    for (const [start, end] of ranges) {
        if (start > at) {
            parts.push({ text: label.slice(at, start), match: false });
        }

        parts.push({ text: label.slice(start, end), match: true });
        at = end;
    }

    if (at < label.length) {
        parts.push({ text: label.slice(at), match: false });
    }

    return parts;
}
