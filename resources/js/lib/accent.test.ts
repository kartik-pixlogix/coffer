import { describe, expect, it } from 'vitest';
import {
    ACCENT_PRESETS,
    DARK_TEXT,
    LIGHT_TEXT,
    MIN_CONTRAST,
    accentCss,
    accentPayload,
    contrast,
    minContrast,
    onColor,
} from '@/lib/accent';

describe('accent presets (D147)', () => {
    it('starts with Coffer lime, the design token values', () => {
        expect(ACCENT_PRESETS[0]).toEqual({
            key: 'lime',
            name: 'Coffer lime',
            stops: ['#d6fe68', '#e2ff8f', '#f0ffc4'],
        });
    });

    it('keeps dark text readable (AA) on every stop of every preset', () => {
        for (const preset of ACCENT_PRESETS) {
            expect(onColor(preset.stops)).toBe(DARK_TEXT);
            expect(minContrast(preset.stops, DARK_TEXT)).toBeGreaterThanOrEqual(
                MIN_CONTRAST,
            );
        }
    });
});

describe('accent colours', () => {
    it('measures WCAG contrast', () => {
        expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrast('#1c1c1e', '#d6fe68')).toBeGreaterThan(14);
    });

    it('switches to white text on a dark gradient', () => {
        expect(onColor(['#1e3a8a', '#1d4ed8', '#312e81'])).toBe(LIGHT_TEXT);
    });

    it('renders no overrides for Coffer lime', () => {
        expect(
            accentCss({
                preset: 'lime',
                stops: ['#d6fe68', '#e2ff8f', '#f0ffc4'],
                angle: 135,
            }),
        ).toBe('');
    });

    // Same fixture as tests/Unit/AccentTest.php: the preview must match the server.
    it('renders the same overrides as the server', () => {
        expect(
            accentCss({
                preset: 'custom',
                stops: ['#1e3a8a', '#1d4ed8', '#312e81'],
                angle: 90,
            }),
        ).toBe(
            ':root{--lime:#1e3a8a;--lime-mid:#1d4ed8;--lime-tint:#312e81' +
                ';--gradient-lime:linear-gradient(90deg,#1e3a8a 0%,#1d4ed8 55%,#312e81 100%)' +
                ';--on-lime:#ffffff;--selected:#bcc4dc}' +
                ':root[data-theme="dark"]{--selected:#161a27}',
        );
    });

    it('sends colours and angle only for a custom gradient', () => {
        expect(
            accentPayload({
                preset: 'sky',
                stops: ['#8ccbff', '#b3dcff', '#ddefff'],
                angle: 135,
            }),
        ).toEqual({ preset: 'sky' });
        expect(
            accentPayload({
                preset: 'custom',
                stops: ['#AABBCC', '#112233', '#445566'],
                angle: 45,
            }),
        ).toEqual({
            preset: 'custom',
            stops: ['#aabbcc', '#112233', '#445566'],
            angle: 45,
        });
    });
});
