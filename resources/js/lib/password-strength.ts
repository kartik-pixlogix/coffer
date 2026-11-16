/*
 * Password strength meters, exactly as the two designs define them. They are hints only:
 * the server's rule is "at least 12 characters" (spec §17), plus a breach check in production.
 */

export const MIN_PASSWORD_LENGTH = 12;

export type StrengthMeter = {
    /** Word beside the label ('' while the field is empty, Profile only). */
    label: string;
    /** Colour of that word (a CSS colour token). */
    ink: string;
    /** Background of the 4 `cf-meter-dots`. */
    dots: [string, string, string, string];
};

/**
 * SignUp.dc.html: one point each for ≥ 8 chars, ≥ 12 chars, anything but letters
 * (or a space), and ≥ 16 chars.
 */
export function signUpStrength(password: string): StrengthMeter & {
    score: number;
} {
    let score = 0;

    if (password.length >= 8) score += 1;
    if (password.length >= 12) score += 1;
    if (/[^a-z]/i.test(password) || /\s/.test(password)) score += 1;
    if (password.length >= 16) score += 1;

    const labels = ['Too short', 'Weak', 'Fair', 'Good', 'Strong'];
    const inks = [
        'var(--negative)',
        'var(--negative)',
        'var(--warning)',
        'var(--ink)',
        'var(--positive)',
    ];
    const on = (n: number) =>
        score >= n
            ? score <= 1
                ? 'var(--chart-negative)'
                : score === 2
                  ? 'var(--warning)'
                  : 'var(--chart-ink)'
            : 'var(--line-strong)';

    return {
        score,
        label: labels[score],
        ink: inks[score],
        dots: [on(1), on(2), on(3), on(4)],
    };
}

/**
 * Profile.dc.html: entropy from length × log2(character pool). Below 40 bits Weak,
 * below 60 Fair, below 80 Strong, else Very strong. "OK to save" needs Strong or
 * better and at least 12 characters.
 */
export function profileStrength(password: string): StrengthMeter & {
    bits: number;
    ok: boolean;
} {
    let pool = 0;

    if (/[a-z]/.test(password)) pool += 26;
    if (/[A-Z]/.test(password)) pool += 26;
    if (/[0-9]/.test(password)) pool += 10;
    if (/[^a-zA-Z0-9]/.test(password)) pool += 15;

    const bits =
        password === '' ? 0 : password.length * Math.log2(Math.max(pool, 2));
    const level =
        password === ''
            ? -1
            : bits < 40
              ? 0
              : bits < 60
                ? 1
                : bits < 80
                  ? 2
                  : 3;
    const labels = ['Weak', 'Fair', 'Strong', 'Very strong'];
    const colours = [
        'var(--negative)',
        'var(--warning)',
        'var(--positive)',
        'var(--positive)',
    ];
    const dot = (k: number) =>
        level >= 0 && k <= level ? colours[level] : 'var(--line-strong)';

    return {
        bits,
        label: level < 0 ? '' : labels[level],
        ink: level < 0 ? '' : colours[level],
        dots: [dot(0), dot(1), dot(2), dot(3)],
        ok: level >= 2 && password.length >= MIN_PASSWORD_LENGTH,
    };
}
