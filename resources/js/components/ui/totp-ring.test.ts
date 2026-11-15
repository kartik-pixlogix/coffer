import { describe, expect, it } from 'vite-plus/test';
import { progressPercent } from './progress-bar';
import { splitTotpCode, totpSecondsLeft } from './totp-ring';
import { trendLabel } from './trend-chip';

describe('totpSecondsLeft', () => {
    it('counts down the wall-clock window from the Unix epoch', () => {
        expect(totpSecondsLeft(0)).toBe(30);
        expect(totpSecondsLeft(12_000)).toBe(18);
        expect(totpSecondsLeft(29_999)).toBe(1);
        expect(totpSecondsLeft(30_000)).toBe(30);
        expect(totpSecondsLeft(65_000, 60)).toBe(55);
    });

    it('counts down to an expiry when given', () => {
        expect(totpSecondsLeft(1_000, 30, 19_000)).toBe(18);
        expect(totpSecondsLeft(1_000, 30, 18_500)).toBe(18);
        expect(totpSecondsLeft(1_000, 30, new Date(500))).toBe(0);
        expect(totpSecondsLeft(0, 30, 90_000)).toBe(30);
    });
});

describe('splitTotpCode', () => {
    it('splits six digits 3 + 3 and eight 4 + 4', () => {
        expect(splitTotpCode('482613')).toBe('482 613');
        expect(splitTotpCode('482 613')).toBe('482 613');
        expect(splitTotpCode('12345678')).toBe('1234 5678');
        expect(splitTotpCode('12')).toBe('12');
    });
});

describe('progressPercent', () => {
    it('fills by value within the range and clamps', () => {
        expect(progressPercent(120000, 0, 300000)).toBe(40);
        expect(progressPercent(104)).toBe(100);
        expect(progressPercent(-3)).toBe(0);
        expect(progressPercent(5, 10, 10)).toBe(0);
    });
});

describe('trendLabel', () => {
    it('says the comparison in words', () => {
        expect(trendLabel(6, 'September')).toBe('Up 6% on September');
        expect(trendLabel(-8, 'September')).toBe('Down 8% on September');
        expect(trendLabel(0)).toBe('No change');
        expect(trendLabel(0.4)).toBe('Up 0.4%');
    });
});
