import { describe, expect, it } from 'vite-plus/test';
import {
    codeToDigits,
    fillCode,
    isCodeComplete,
    sanitizeCode,
} from './code-input.utils';

const empty = () => codeToDigits('');

describe('sanitizeCode', () => {
    it.each([
        ['482613', '482613'],
        ['482 613', '482613'],
        ['48-26-13', '482613'],
        ['Your code is 482613.', '482613'],
        ['1234567', '123456'],
        ['abc', ''],
    ])('%s → %s', (raw, clean) => {
        expect(sanitizeCode(raw)).toBe(clean);
    });
});

describe('codeToDigits', () => {
    it('pads to six boxes', () => {
        expect(codeToDigits('48')).toEqual(['4', '8', '', '', '', '']);
    });
});

describe('fillCode', () => {
    it('types one digit and advances', () => {
        expect(fillCode(empty(), 0, '4')).toEqual({
            digits: ['4', '', '', '', '', ''],
            focus: 1,
        });
    });

    it('ignores letters', () => {
        expect(fillCode(empty(), 2, 'x')).toEqual({
            digits: empty(),
            focus: 2,
        });
    });

    it('fills every box from the first on a full paste, wherever the caret is', () => {
        expect(fillCode(empty(), 3, '482 613')).toEqual({
            digits: ['4', '8', '2', '6', '1', '3'],
            focus: 5,
        });
    });

    it('fills from the current box on a partial paste', () => {
        expect(fillCode(codeToDigits('48'), 2, '26')).toEqual({
            digits: ['4', '8', '2', '6', '', ''],
            focus: 4,
        });
    });

    it('drops digits past the last box', () => {
        expect(fillCode(codeToDigits('4826'), 4, '139')).toEqual({
            digits: ['4', '8', '2', '6', '1', '3'],
            focus: 5,
        });
    });
});

describe('isCodeComplete', () => {
    it('needs a digit in every box', () => {
        expect(isCodeComplete(codeToDigits('48261'))).toBe(false);
        expect(isCodeComplete(codeToDigits('482613'))).toBe(true);
    });
});
