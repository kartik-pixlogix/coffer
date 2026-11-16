/*
 * The vault password generator (spec §14.4, VaultAddLogin.dc.html): random passwords
 * of 12–64 characters or passphrases of 3–8 words. Randomness comes from
 * crypto.getRandomValues with rejection sampling, so every character and word is
 * equally likely (no Math.random, no modulo bias). Generated values stay in component
 * state only.
 */

import { PASSPHRASE_WORDS } from '@/lib/passphrase-words';

export const LENGTH_MIN = 12;
export const LENGTH_MAX = 64;
export const LENGTH_DEFAULT = 20;
export const WORDS_MIN = 3;
export const WORDS_MAX = 8;
export const WORDS_DEFAULT = 5;

/** Letters without the look-alikes l, I and O (as on the board). */
export const LETTERS = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
/** Digits without 0 and 1. */
export const DIGITS = '23456789';
export const SYMBOLS = '!@#$%^&*-_=+?';

/** The two-digit number a passphrase can end with: 10–99. */
const NUMBER_MIN = 10;
const NUMBER_COUNT = 90;

export type GeneratorMode = 'random' | 'passphrase';

export type GeneratorSettings = {
    mode: GeneratorMode;
    /** Random: characters, 12–64. */
    length: number;
    /** Random: include symbols. */
    symbols: boolean;
    /** Random: include numbers. Passphrase: end with a two-digit number. */
    digits: boolean;
    /** Passphrase: words, 3–8. */
    words: number;
    /** Passphrase: capitalise each word. */
    caps: boolean;
};

export const DEFAULT_SETTINGS: GeneratorSettings = {
    mode: 'random',
    length: LENGTH_DEFAULT,
    symbols: true,
    digits: true,
    words: WORDS_DEFAULT,
    caps: true,
};

/** Fills a Uint32Array with random values (crypto.getRandomValues by default). */
export type RandomSource = (buffer: Uint32Array) => void;

const cryptoSource: RandomSource = (buffer) => {
    crypto.getRandomValues(buffer);
};

const clamp = (value: number, min: number, max: number): number =>
    Math.min(max, Math.max(min, Math.round(value)));

/** A uniform integer in [0, max) by rejection sampling over 32-bit values. */
export function randomInt(
    max: number,
    source: RandomSource = cryptoSource,
): number {
    if (!Number.isInteger(max) || max < 1 || max > 2 ** 32) {
        throw new RangeError('max must be an integer from 1 to 2^32.');
    }

    // The largest multiple of max that fits in 32 bits; values above it are redrawn.
    const limit = Math.floor(2 ** 32 / max) * max;
    const buffer = new Uint32Array(1);

    for (;;) {
        source(buffer);

        if (buffer[0] < limit) {
            return buffer[0] % max;
        }
    }
}

/** The characters a random password draws from. */
export function charset(
    settings: Pick<GeneratorSettings, 'symbols' | 'digits'>,
) {
    return (
        LETTERS +
        (settings.digits ? DIGITS : '') +
        (settings.symbols ? SYMBOLS : '')
    );
}

/**
 * A random password. Every chosen class (letters, numbers, symbols) appears at least
 * once: a draw that misses one is thrown away and redrawn, which keeps the result
 * uniform over the passwords that qualify.
 */
export function randomPassword(
    settings: Pick<GeneratorSettings, 'length' | 'symbols' | 'digits'>,
    source: RandomSource = cryptoSource,
): string {
    const length = clamp(settings.length, LENGTH_MIN, LENGTH_MAX);
    const set = charset(settings);
    const classes = [
        /[a-z]/,
        /[A-Z]/,
        ...(settings.digits ? [/[2-9]/] : []),
        ...(settings.symbols ? [/[^a-zA-Z0-9]/] : []),
    ];

    for (;;) {
        let out = '';

        for (let i = 0; i < length; i++) {
            out += set[randomInt(set.length, source)];
        }

        if (classes.every((re) => re.test(out))) {
            return out;
        }
    }
}

/** A passphrase: words from the list joined with "-", optionally capitalised, plus a number. */
export function passphrase(
    settings: Pick<GeneratorSettings, 'words' | 'caps' | 'digits'>,
    source: RandomSource = cryptoSource,
    list: readonly string[] = PASSPHRASE_WORDS,
): string {
    const count = clamp(settings.words, WORDS_MIN, WORDS_MAX);
    const parts: string[] = [];

    for (let i = 0; i < count; i++) {
        const word = list[randomInt(list.length, source)];
        parts.push(
            settings.caps ? word.charAt(0).toUpperCase() + word.slice(1) : word,
        );
    }

    if (settings.digits) {
        parts.push(String(NUMBER_MIN + randomInt(NUMBER_COUNT, source)));
    }

    return parts.join('-');
}

export function generate(
    settings: GeneratorSettings,
    source: RandomSource = cryptoSource,
): string {
    return settings.mode === 'passphrase'
        ? passphrase(settings, source)
        : randomPassword(settings, source);
}

/**
 * Entropy of the generator's output in bits: length × log2(charset) for random
 * passwords, words × log2(list size) (+ log2(90) for the number) for passphrases.
 */
export function generatorBits(
    settings: GeneratorSettings,
    listSize: number = PASSPHRASE_WORDS.length,
): number {
    if (settings.mode === 'passphrase') {
        return (
            clamp(settings.words, WORDS_MIN, WORDS_MAX) * Math.log2(listSize) +
            (settings.digits ? Math.log2(NUMBER_COUNT) : 0)
        );
    }

    return (
        clamp(settings.length, LENGTH_MIN, LENGTH_MAX) *
        Math.log2(charset(settings).length)
    );
}

/** "≈ 113 bits" or "≈ 65 bits · 4,441-word list". */
export function bitsLabel(
    settings: GeneratorSettings,
    listSize: number = PASSPHRASE_WORDS.length,
): string {
    const bits = `≈ ${Math.round(generatorBits(settings, listSize))} bits`;

    return settings.mode === 'passphrase'
        ? `${bits} · ${listSize.toLocaleString('en-IN')}-word list`
        : bits;
}

/** zxcvbn's 0–4 score from bits, for before zxcvbn has loaded. */
export function scoreFromBits(bits: number): number {
    return bits < 28 ? 0 : bits < 40 ? 1 : bits < 60 ? 2 : bits < 80 ? 3 : 4;
}
