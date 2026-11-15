/** A run of text, marked when it matches the query. */
export type HighlightSegment = { text: string; match: boolean };

/**
 * Split `text` around the first case-insensitive occurrence of `query`, keeping the
 * original casing: ("Kabir Shah", "sha") → Kabir · [Sha] · h.
 */
export function highlightMatch(
    text: string,
    query: string,
): HighlightSegment[] {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
        return [{ text, match: false }];
    }

    const at = text.toLowerCase().indexOf(needle);

    if (at === -1) {
        return [{ text, match: false }];
    }

    return [
        { text: text.slice(0, at), match: false },
        { text: text.slice(at, at + needle.length), match: true },
        { text: text.slice(at + needle.length), match: false },
    ].filter((segment) => segment.text !== '');
}

export type FilterableOption = {
    label: string;
    /** Secondary text after the label ("Bluefin Infotech"); searched too. */
    meta?: string;
    /** Extra words that match but aren't shown (email, short code). */
    keywords?: string[];
};

export type FilteredOption<T extends FilterableOption> = {
    option: T;
    /** Original index in the options list. */
    index: number;
    label: HighlightSegment[];
    meta: HighlightSegment[] | null;
};

/**
 * Options whose label, meta or keywords contain the query (case-insensitive), in
 * their original order, with the match highlighted in the label (or else the meta).
 * An empty query returns everything.
 */
export function filterOptions<T extends FilterableOption>(
    options: readonly T[],
    query: string,
): FilteredOption<T>[] {
    const needle = query.trim().toLowerCase();
    const results: FilteredOption<T>[] = [];

    options.forEach((option, index) => {
        const inLabel = option.label.toLowerCase().includes(needle);
        const inMeta = option.meta?.toLowerCase().includes(needle) ?? false;
        const inKeywords =
            option.keywords?.some((word) =>
                word.toLowerCase().includes(needle),
            ) ?? false;

        if (needle !== '' && !inLabel && !inMeta && !inKeywords) {
            return;
        }

        results.push({
            option,
            index,
            label: highlightMatch(option.label, inLabel ? needle : ''),
            meta:
                option.meta === undefined
                    ? null
                    : highlightMatch(
                          option.meta,
                          !inLabel && inMeta ? needle : '',
                      ),
        });
    });

    return results;
}

/**
 * The next enabled option index from `from` in `direction`, wrapping around.
 * Returns -1 when every option is disabled.
 */
export function nextEnabledIndex(
    disabled: readonly boolean[],
    from: number,
    direction: 1 | -1,
): number {
    const count = disabled.length;
    // Nothing active yet: Down starts at the top, Up at the bottom.
    const start = from < 0 ? (direction === 1 ? -1 : count) : from;

    for (let step = 1; step <= count; step++) {
        const index = (((start + direction * step) % count) + count) % count;

        if (!disabled[index]) {
            return index;
        }
    }

    return -1;
}
