import { formatRupeeInput } from '@/lib/format';

/** Characters that carry meaning in an amount (digits and the decimal point). */
const isSignificant = (char: string): boolean => /[\d.]/.test(char);

const countSignificant = (text: string): number =>
    text.split('').filter(isSignificant).length;

/**
 * Re-group what was typed into an amount field and work out where the caret goes.
 *
 * `raw` is the input's value right after the edit and `caret` its selectionStart.
 * The caret stays after the same digit it followed before grouping changed, so
 * typing "5" into "1,234|" gives "12,345|" and not "12,34|5".
 */
export function reformatAmountInput(
    raw: string,
    caret: number,
): { text: string; caret: number } {
    const text = formatRupeeInput(raw);
    const before = formatRupeeInput(raw.slice(0, Math.max(0, caret)));
    const wanted = Math.min(countSignificant(before), countSignificant(text));

    if (wanted === 0) {
        return { text, caret: 0 };
    }

    let seen = 0;

    for (let index = 0; index < text.length; index++) {
        if (isSignificant(text[index])) {
            seen++;

            if (seen === wanted) {
                return { text, caret: index + 1 };
            }
        }
    }

    return { text, caret: text.length };
}

/**
 * Where to put the caret before a Backspace/Delete so it removes a digit instead of
 * a grouping comma (which would come straight back): "1,|234" + Backspace deletes "1".
 * Returns null when the key should act normally.
 */
export function skipGroupingComma(
    text: string,
    caret: number,
    key: 'Backspace' | 'Delete',
): number | null {
    if (key === 'Backspace' && caret > 0 && text[caret - 1] === ',') {
        return caret - 1;
    }

    if (key === 'Delete' && text[caret] === ',') {
        return caret + 1;
    }

    return null;
}
