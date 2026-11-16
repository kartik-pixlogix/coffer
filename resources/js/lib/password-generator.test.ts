import { describe, expect, it } from 'vite-plus/test';
import {
    DEFAULT_SETTINGS,
    DIGITS,
    LETTERS,
    SYMBOLS,
    bitsLabel,
    charset,
    generate,
    generatorBits,
    passphrase,
    randomInt,
    randomPassword,
} from './password-generator';
import type { RandomSource } from './password-generator';
import { PASSPHRASE_WORDS } from './passphrase-words';

/** A source that replays fixed 32-bit values, for checking rejection sampling. */
const replay = (values: number[]): RandomSource => {
    let i = 0;

    return (buffer) => {
        buffer[0] = values[i++ % values.length];
    };
};

describe('randomInt', () => {
    it('stays in range', () => {
        for (let i = 0; i < 2000; i++) {
            const n = randomInt(7);
            expect(n).toBeGreaterThanOrEqual(0);
            expect(n).toBeLessThan(7);
        }
    });

    it('redraws values above the last whole multiple (no modulo bias)', () => {
        // limit for 10 is 4294967290; 4294967295 must be rejected, then 13 → 3.
        expect(randomInt(10, replay([4294967295, 13]))).toBe(3);
        expect(randomInt(10, replay([4294967289]))).toBe(9);
    });

    it('rejects a bad range', () => {
        expect(() => randomInt(0)).toThrow(RangeError);
        expect(() => randomInt(1.5)).toThrow(RangeError);
    });

    it('is roughly uniform', () => {
        const counts = Array(4).fill(0);

        for (let i = 0; i < 8000; i++) {
            counts[randomInt(4)]++;
        }

        counts.forEach((c) => expect(Math.abs(c - 2000)).toBeLessThan(250));
    });
});

describe('randomPassword', () => {
    it('honours the length and clamps it to 12–64', () => {
        expect(
            randomPassword({ length: 20, symbols: true, digits: true }),
        ).toHaveLength(20);
        expect(
            randomPassword({ length: 4, symbols: true, digits: true }),
        ).toHaveLength(12);
        expect(
            randomPassword({ length: 99, symbols: false, digits: true }),
        ).toHaveLength(64);
    });

    it('draws only from the chosen character set and uses every class', () => {
        for (let i = 0; i < 50; i++) {
            const all = randomPassword({
                length: 16,
                symbols: true,
                digits: true,
            });
            expect(
                all
                    .split('')
                    .every((c) =>
                        charset({ symbols: true, digits: true }).includes(c),
                    ),
            ).toBe(true);
            expect(all).toMatch(/[2-9]/);
            expect(all).toMatch(/[!@#$%^&*\-_=+?]/);
            expect(all).toMatch(/[a-z]/);
            expect(all).toMatch(/[A-Z]/);

            const letters = randomPassword({
                length: 16,
                symbols: false,
                digits: false,
            });
            expect(letters).toMatch(/^[a-zA-Z]+$/);
            expect(letters).not.toMatch(/[lIO]/);
        }
    });
});

describe('passphrase', () => {
    it('joins words from the list with dashes', () => {
        const phrase = passphrase({ words: 5, caps: false, digits: false });
        const words = phrase.split('-');
        expect(words).toHaveLength(5);
        words.forEach((w) => expect(PASSPHRASE_WORDS).toContain(w));
    });

    it('capitalises and adds a two-digit number', () => {
        const parts = passphrase({ words: 3, caps: true, digits: true }).split(
            '-',
        );
        expect(parts).toHaveLength(4);
        parts.slice(0, 3).forEach((w) => expect(w).toMatch(/^[A-Z][a-z]+$/));
        expect(Number(parts[3])).toBeGreaterThanOrEqual(10);
        expect(Number(parts[3])).toBeLessThanOrEqual(99);
    });

    it('clamps the word count to 3–8', () => {
        expect(
            passphrase({ words: 1, caps: false, digits: false }).split('-'),
        ).toHaveLength(3);
        expect(
            passphrase({ words: 12, caps: false, digits: false }).split('-'),
        ).toHaveLength(8);
    });
});

describe('the word list', () => {
    it('has at least 1,024 distinct lowercase words of 3–8 letters', () => {
        expect(PASSPHRASE_WORDS.length).toBeGreaterThanOrEqual(1024);
        expect(new Set(PASSPHRASE_WORDS).size).toBe(PASSPHRASE_WORDS.length);
        PASSPHRASE_WORDS.forEach((w) => expect(w).toMatch(/^[a-z]{3,8}$/));
    });
});

describe('entropy', () => {
    it('is length × log2(charset) for random passwords', () => {
        const size = LETTERS.length + DIGITS.length + SYMBOLS.length;
        expect(size).toBe(70);
        expect(generatorBits(DEFAULT_SETTINGS)).toBeCloseTo(
            20 * Math.log2(70),
            6,
        );
        expect(
            generatorBits({
                ...DEFAULT_SETTINGS,
                symbols: false,
                digits: false,
            }),
        ).toBeCloseTo(20 * Math.log2(LETTERS.length), 6);
        expect(bitsLabel(DEFAULT_SETTINGS)).toBe('≈ 123 bits');
    });

    it('is words × log2(list size) (+ log2 90) for passphrases', () => {
        const settings = { ...DEFAULT_SETTINGS, mode: 'passphrase' as const };
        const n = PASSPHRASE_WORDS.length;
        expect(generatorBits(settings)).toBeCloseTo(
            5 * Math.log2(n) + Math.log2(90),
            6,
        );
        expect(generatorBits({ ...settings, digits: false }, 7776)).toBeCloseTo(
            5 * Math.log2(7776),
            6,
        );
        expect(bitsLabel({ ...settings, digits: false }, 7776)).toBe(
            '≈ 65 bits · 7,776-word list',
        );
    });

    it('generate() follows the mode', () => {
        expect(generate(DEFAULT_SETTINGS)).toHaveLength(20);
        expect(
            generate({ ...DEFAULT_SETTINGS, mode: 'passphrase' }).split('-'),
        ).toHaveLength(6);
    });
});
