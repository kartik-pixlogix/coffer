import presetData from '@/lib/accent-presets.json';
import type { Accent } from '@/types/auth';

/**
 * The brand gradient (D147), mirroring app/Support/Accent.php so the picker can preview
 * exactly what the server will render: the same presets, the same text-colour choice and
 * the same `:root` overrides, written into the head's `<style id="cf-accent">`.
 */

export type AccentStops = [string, string, string];

export type AccentPreset = { key: string; name: string; stops: AccentStops };

export type AccentChoice = Pick<Accent, 'preset' | 'stops' | 'angle'>;

export const DEFAULT_PRESET = 'lime';
export const CUSTOM = 'custom';
export const DEFAULT_ANGLE = 135;
export const DARK_TEXT = '#1c1c1e';
export const LIGHT_TEXT = '#ffffff';
/** Below this, text on the gradient fails WCAG AA for body text. */
export const MIN_CONTRAST = 4.5;

const DARK_SURFACE = '#15161a';
const HEX = /^#[0-9a-f]{6}$/i;

export const ACCENT_PRESETS: AccentPreset[] = Object.entries(
    presetData as Record<string, { name: string; stops: string[] }>,
).map(([key, preset]) => ({
    key,
    name: preset.name,
    stops: preset.stops as AccentStops,
}));

export const isHex = (value: string): boolean => HEX.test(value);

const rgb = (hex: string): [number, number, number] => [
    parseInt(hex.slice(1, 3), 16),
    parseInt(hex.slice(3, 5), 16),
    parseInt(hex.slice(5, 7), 16),
];

const luminance = (hex: string): number => {
    const channel = (value: number): number => {
        const c = value / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    const [r, g, b] = rgb(hex);

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
};

export const contrast = (a: string, b: string): number => {
    const la = luminance(a);
    const lb = luminance(b);

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
};

/** The lowest contrast of a text colour against any stop. */
export const minContrast = (stops: readonly string[], text: string): number =>
    Math.min(...stops.map((stop) => contrast(stop, text)));

/** Dark or white text, whichever has the better worst case across the stops. */
export const onColor = (stops: readonly string[]): string =>
    minContrast(stops, DARK_TEXT) >= minContrast(stops, LIGHT_TEXT)
        ? DARK_TEXT
        : LIGHT_TEXT;

const mix = (color: string, base: string, amount: number): string => {
    const c = rgb(color);
    const b = rgb(base);

    return `#${[0, 1, 2]
        .map((i) =>
            Math.round(c[i] * amount + b[i] * (1 - amount))
                .toString(16)
                .padStart(2, '0'),
        )
        .join('')}`;
};

export const gradientCss = (stops: readonly string[], angle: number): string =>
    `linear-gradient(${angle}deg, ${stops[0]} 0%, ${stops[1]} 55%, ${stops[2]} 100%)`;

/** The `:root` overrides the server renders (Accent::css), or '' for Coffer lime. */
export function accentCss(accent: AccentChoice): string {
    if (accent.preset === DEFAULT_PRESET) {
        return '';
    }

    const [a, b, c] = accent.stops;

    return (
        `:root{--lime:${a};--lime-mid:${b};--lime-tint:${c}` +
        `;--gradient-lime:linear-gradient(${accent.angle}deg,${a} 0%,${b} 55%,${c} 100%)` +
        `;--on-lime:${onColor(accent.stops)};--selected:${mix(a, '#ffffff', 0.3)}}` +
        `:root[data-theme="dark"]{--selected:${mix(a, DARK_SURFACE, 0.12)}}`
    );
}

/** Show a gradient now (preview or saved), by rewriting the head's accent style. */
export function applyAccent(accent: AccentChoice): void {
    if (typeof document === 'undefined') {
        return;
    }

    let style = document.getElementById('cf-accent');

    if (style === null) {
        style = document.createElement('style');
        style.id = 'cf-accent';
        document.head.appendChild(style);
    }

    style.textContent = accentCss(accent);
}

/** A preset as a full choice (presets always use the default angle). */
export const presetChoice = (preset: AccentPreset): AccentChoice => ({
    preset: preset.key,
    stops: preset.stops,
    angle: DEFAULT_ANGLE,
});

/** What the save request sends: custom carries its colours and angle. */
export const accentPayload = (
    accent: AccentChoice,
): Record<string, string | number | string[]> =>
    accent.preset === CUSTOM
        ? {
              preset: CUSTOM,
              stops: accent.stops.map((s) => s.toLowerCase()),
              angle: accent.angle,
          }
        : { preset: accent.preset };
