import { describe, expect, it } from 'vite-plus/test';
import { reformatAmountInput, skipGroupingComma } from './amount-input.utils';

describe('reformatAmountInput', () => {
    it.each([
        // [raw after the edit, caret, expected text, expected caret]
        ['12345', 5, '12,345', 6], // typed 5 at the end of "1,234"
        ['1,2345', 6, '12,345', 6],
        ['1234567', 7, '12,34,567', 9],
        ['1,234', 1, '1,234', 1], // caret after the 1 stays after the 1
        ['91,234', 1, '91,234', 1], // typed 9 at the start
        ['1,1234', 3, '11,234', 2], // typed after the comma: caret after the second 1
        ['1234', 1, '1,234', 1], // deleted a comma: caret stays after the 1
        ['1,234.5', 7, '1,234.5', 7],
        ['1,234.567', 9, '1,234.56', 8], // third decimal dropped
        ['.5', 2, '0.5', 3],
        ['1,234-', 6, '1,234', 5], // a minus is never accepted
        ['-1,234', 1, '1,234', 0],
        ['1,2a34', 4, '1,234', 3], // letters are dropped, caret after the 2
        ['', 0, '', 0],
        ['0012', 4, '12', 2],
    ])('%s with caret %i → %s caret %i', (raw, caret, text, nextCaret) => {
        expect(reformatAmountInput(raw, caret)).toEqual({
            text,
            caret: nextCaret,
        });
    });
});

describe('skipGroupingComma', () => {
    it('steps over a comma before Backspace', () => {
        expect(skipGroupingComma('1,234', 2, 'Backspace')).toBe(1);
    });

    it('steps over a comma before Delete', () => {
        expect(skipGroupingComma('1,234', 1, 'Delete')).toBe(2);
    });

    it('leaves other positions alone', () => {
        expect(skipGroupingComma('1,234', 3, 'Backspace')).toBeNull();
        expect(skipGroupingComma('1,234', 0, 'Backspace')).toBeNull();
        expect(skipGroupingComma('1,234', 5, 'Delete')).toBeNull();
    });
});
