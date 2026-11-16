import { describe, expect, it } from 'vite-plus/test';
import { profileStrength, signUpStrength } from './password-strength';

describe('signUpStrength (SignUp.dc.html)', () => {
    it('scores length and variety', () => {
        expect(signUpStrength('').label).toBe('Too short');
        expect(signUpStrength('abcdefgh').label).toBe('Weak');
        expect(signUpStrength('abcdefghijkl').label).toBe('Fair');
        expect(signUpStrength('abcdefghijk1').label).toBe('Good');
        expect(signUpStrength('a short sentence works').label).toBe('Strong');
    });

    it('colours the dots by score', () => {
        expect(signUpStrength('abcdefgh').dots).toEqual([
            'var(--chart-negative)',
            'var(--line-strong)',
            'var(--line-strong)',
            'var(--line-strong)',
        ]);
        expect(signUpStrength('a short sentence works').dots).toEqual(
            Array(4).fill('var(--chart-ink)'),
        );
    });
});

describe('profileStrength (Profile.dc.html)', () => {
    it('is blank for an empty field', () => {
        expect(profileStrength('')).toMatchObject({ label: '', ok: false });
    });

    it('uses entropy bands', () => {
        expect(profileStrength('abcdef').label).toBe('Weak'); // 6 × 4.7 ≈ 28 bits
        expect(profileStrength('abcdefghijkl').label).toBe('Fair'); // ≈ 56
        expect(profileStrength('Abcdefghijk1').label).toBe('Strong'); // 12 × 5.95 ≈ 71
        expect(profileStrength('Correct horse battery 9').label).toBe(
            'Very strong',
        );
    });

    it('needs Strong and 12 characters to be OK', () => {
        expect(profileStrength('Ab1!Ab1!Ab1').ok).toBe(false); // 11 chars
        expect(profileStrength('Abcdefghijk1').ok).toBe(true);
    });
});
