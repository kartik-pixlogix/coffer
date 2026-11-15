/** Keep only digits, at most `length` of them: "482 613" → "482613", "48-26" → "4826". */
export function sanitizeCode(raw: string, length = 6): string {
    return raw.replace(/\D/g, '').slice(0, length);
}

/** Split a code into one entry per box, padding with empty strings. */
export function codeToDigits(code: string, length = 6): string[] {
    const clean = sanitizeCode(code, length);

    return Array.from({ length }, (_, index) => clean[index] ?? '');
}

/**
 * Put typed or pasted text into the boxes starting at `index`. Non-digits are
 * dropped; extra digits beyond the last box are ignored. Returns the new digits and
 * the box to focus next (the one after the last digit written, or the last box).
 * A paste of a whole code (6 digits) always fills from the first box.
 */
export function fillCode(
    digits: readonly string[],
    index: number,
    input: string,
): { digits: string[]; focus: number } {
    const length = digits.length;
    const clean = sanitizeCode(input, length);
    const next = [...digits];

    if (clean === '') {
        return { digits: next, focus: index };
    }

    const start = clean.length === length ? 0 : index;
    let position = start;

    for (const digit of clean) {
        if (position >= length) {
            break;
        }

        next[position] = digit;
        position++;
    }

    return { digits: next, focus: Math.min(position, length - 1) };
}

/** True once every box holds a digit. */
export const isCodeComplete = (digits: readonly string[]): boolean =>
    digits.every((digit) => /^\d$/.test(digit));
