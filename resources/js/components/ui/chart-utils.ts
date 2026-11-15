import { MINUS, formatMonth, monthShortName, toDate } from '@/lib/format';

/*
 * Pure helpers shared by the chart components (AreaChart, BarChart, DonutChart,
 * GroupedBars). No React, no DOM: unit-tested in chart-utils.test.ts.
 * Amounts are always integer paise.
 */

export type Point = readonly [x: number, y: number];

/* ------------------------------------------------------------------ scales */

/** Linear map from a domain onto a range. A zero-width domain maps to the range start. */
export function linearScale(
    [d0, d1]: readonly [number, number],
    [r0, r1]: readonly [number, number],
): (value: number) => number {
    const span = d1 - d0;

    return (value) =>
        span === 0 ? r0 : r0 + ((value - d0) / span) * (r1 - r0);
}

/** Steps a "nice" axis may use, per power of ten. */
const NICE_STEPS = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];

export type NiceScale = {
    /** Lowest tick (0 unless the data goes below zero), paise. */
    min: number;
    /** Highest tick, paise. */
    max: number;
    /** Distance between ticks, paise. */
    step: number;
    /** Ticks from max down to min (the order the y-axis lists them), paise. */
    ticks: number[];
};

/**
 * A y-axis for money: round compact ticks (₹12L ₹8L ₹4L ₹0) covering the data.
 * Always includes zero. `intervals` is the number of gaps (labels = intervals + 1).
 */
export function niceScale(values: readonly number[], intervals = 3): NiceScale {
    const finite = values.filter((v) => Number.isFinite(v));
    const lo = Math.min(0, ...finite);
    const hi = Math.max(0, ...finite);
    const span = hi - lo || 100; // all zero: a ₹1 scale keeps the axis drawable
    const raw = span / intervals;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    let step = magnitude * 10;

    for (const candidate of NICE_STEPS) {
        const s = candidate * magnitude;

        if (s >= raw) {
            step = s;
            break;
        }
    }

    step = Math.max(1, Math.round(step));

    // Grow the step until `intervals` gaps of it cover [lo, hi] on step multiples.
    for (;;) {
        const min = Math.floor(lo / step) * step;
        const max = min + step * intervals;

        if (max >= hi) {
            const ticks = Array.from(
                { length: intervals + 1 },
                (_, i) => max - i * step,
            );

            return { min: min === 0 ? 0 : min, max, step, ticks };
        }

        const next = NICE_STEPS.find((c) => c * magnitude > step);
        step = next ? Math.round(next * magnitude) : step * 2;
    }
}

/* ------------------------------------------------------------------- paths */

const f1 = (n: number) => n.toFixed(1);

/**
 * Smooth line through points: Catmull-Rom converted to cubic Bézier, exactly as the
 * design prototypes draw it (Main.dc.html `smooth()`), coordinates to one decimal.
 */
export function smoothPath(points: readonly Point[]): string {
    if (points.length === 0) {
        return '';
    }

    let d = `M${f1(points[0][0])} ${f1(points[0][1])}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;
        const c1x = p1[0] + (p2[0] - p0[0]) / 6;
        const c1y = p1[1] + (p2[1] - p0[1]) / 6;
        const c2x = p2[0] - (p3[0] - p1[0]) / 6;
        const c2y = p2[1] - (p3[1] - p1[1]) / 6;

        d += `C${f1(c1x)} ${f1(c1y)} ${f1(c2x)} ${f1(c2y)} ${f1(p2[0])} ${f1(p2[1])}`;
    }

    return d;
}

/** Close a line path down to the baseline for the area wash: `…L520 160L0 160Z`. */
export function areaPath(line: string, width: number, height: number): string {
    return line === '' ? '' : `${line}L${width} ${height}L0 ${height}Z`;
}

export type AreaGeometry = {
    xs: number[];
    y: (paise: number) => number;
};

/**
 * Area/line chart geometry in viewBox units: points spread evenly from 0 to `width`;
 * values map from `max` (with `headroom` units clear at the top) down to 0 at `height`,
 * as in the design (y = H − v / max × (H − 14)).
 */
export function areaGeometry(
    count: number,
    max: number,
    width: number,
    height: number,
    headroom = 14,
): AreaGeometry {
    const xs = Array.from({ length: count }, (_, i) =>
        count <= 1 ? width / 2 : (i * width) / (count - 1),
    );
    const y = linearScale([0, max], [height, headroom]);

    return { xs, y };
}

/** Index of the point nearest to a horizontal fraction (0–1) of the plot. */
export function nearestIndex(fraction: number, count: number): number {
    if (count <= 1) {
        return 0;
    }

    const clamped = Math.min(1, Math.max(0, fraction));

    return Math.round(clamped * (count - 1));
}

/* -------------------------------------------------------------------- bars */

/**
 * Bar height in px for `value` when the largest |value| fills `maxHeight`, never
 * thinner than `minHeight` so small and negative months stay visible.
 */
export function barHeight(
    value: number,
    maxAbs: number,
    maxHeight: number,
    minHeight = 6,
): number {
    if (maxAbs === 0) {
        return minHeight;
    }

    return Math.max(
        minHeight,
        Math.round((Math.abs(value) / maxAbs) * maxHeight),
    );
}

/** Largest absolute value (0 for none). */
export function maxAbs(values: readonly number[]): number {
    return values.reduce((m, v) => Math.max(m, Math.abs(v)), 0);
}

/**
 * The value chip on a bar: lakhs and crores with one fixed decimal (₹6.0L, ₹1.2Cr) so
 * the chip keeps its width, thousands whole (−₹33K), as in the BarChart preview.
 */
export function formatBarChip(paise: number): string {
    const rupees = Math.abs(paise) / 100;
    const sign = paise < 0 ? MINUS : '';

    if (rupees >= 1_00_00_000) {
        return `${sign}₹${(rupees / 1_00_00_000).toFixed(1)}Cr`;
    }

    if (rupees >= 1_00_000) {
        return `${sign}₹${(rupees / 1_00_000).toFixed(1)}L`;
    }

    if (rupees >= 1_000) {
        return `${sign}₹${Math.round(rupees / 1_000)}K`;
    }

    return `${sign}₹${Math.round(rupees)}`;
}

/* ------------------------------------------------------------------- donut */

export const PARTNER_PALETTE = [
    'var(--partner-1)',
    'var(--partner-2)',
    'var(--partner-3)',
    'var(--partner-4)',
    'var(--partner-5)',
    'var(--partner-6)',
] as const;

/** Categories beside a partner chart: never borrow a partner's colour. */
export const BRAND_RAMP = [
    'var(--chart-ink)',
    'var(--lime)',
    'var(--ink-secondary)',
    'var(--ink-faint)',
    'var(--line-strong)',
] as const;

/** Series colours for grouped bars and multi-series charts, in order, never cycled. */
export const SERIES_PALETTE = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
    'var(--chart-7)',
    'var(--chart-8)',
] as const;

/** Gap between donut slices, degrees, filled with surface-card. */
export const DONUT_GAP_DEG = 1.2;

export type DonutInput = {
    name: string;
    /** Integer paise (or any non-negative amount). */
    value: number;
    color?: string;
};

export type DonutSegment = {
    name: string;
    value: number;
    color: string;
    /** Rounded whole percent for the legend and aria-label ("34%"). */
    percent: number;
    /** Exact share 0–100 for the tooltip (28.4%). */
    share: number;
    startDeg: number;
    endDeg: number;
};

/**
 * Slices for a donut: zero slices dropped, colours from the palette in order, and
 * slices beyond the palette folded into one "Other" slice in the last colour.
 */
export function donutSegments(
    slices: readonly DonutInput[],
    palette: readonly string[],
    otherLabel = 'Other',
): DonutSegment[] {
    const positive = slices.filter((s) => s.value > 0);
    const limit = palette.length;
    const kept =
        positive.length > limit
            ? [
                  ...positive.slice(0, limit - 1),
                  {
                      name: otherLabel,
                      value: positive
                          .slice(limit - 1)
                          .reduce((sum, s) => sum + s.value, 0),
                  },
              ]
            : positive;
    const total = kept.reduce((sum, s) => sum + s.value, 0);
    let acc = 0;

    return kept.map((slice, i) => {
        const startDeg = total === 0 ? 0 : (acc / total) * 360;
        acc += slice.value;
        const endDeg = total === 0 ? 0 : (acc / total) * 360;
        const share = total === 0 ? 0 : (slice.value / total) * 100;

        return {
            name: slice.name,
            value: slice.value,
            color: ('color' in slice && slice.color) || palette[i],
            percent: Math.round(share),
            share,
            startDeg,
            endDeg,
        };
    });
}

const f2 = (n: number) => n.toFixed(2);

/** `conic-gradient(…)` with a 1.2° surface-card gap after every slice (Main.dc.html). */
export function donutGradient(segments: readonly DonutSegment[]): string {
    if (segments.length === 0) {
        return 'var(--surface-muted)';
    }

    const stops = segments.flatMap((s) => {
        const end = Math.max(s.startDeg, s.endDeg - DONUT_GAP_DEG);

        return [
            `${s.color} ${f2(s.startDeg)}deg ${f2(end)}deg`,
            `var(--surface-card) ${f2(end)}deg ${f2(s.endDeg)}deg`,
        ];
    });

    return `conic-gradient(${stops.join(', ')})`;
}

/**
 * Clockwise angle from 12 o'clock (degrees 0–360) of a point relative to the centre,
 * the way conic-gradient lays out its stops.
 */
export function angleFromTop(dx: number, dy: number): number {
    const deg = (Math.atan2(dx, -dy) * 180) / Math.PI;

    return deg < 0 ? deg + 360 : deg;
}

/** Index of the segment containing `deg`, or -1. */
export function segmentAt(
    segments: readonly DonutSegment[],
    deg: number,
): number {
    return segments.findIndex((s) => deg >= s.startDeg && deg < s.endDeg);
}

/* ------------------------------------------------------------------ labels */

export type MonthLabels = { short: string; period: string };

/** "2026-09-01" → { short: "Sep", period: "Sep 2026" }. */
export function monthLabels(date: string | Date): MonthLabels {
    const d = toDate(date);

    return { short: monthShortName(d.getMonth()), period: formatMonth(d) };
}

/* -------------------------------------------------------------------- ring */

/** Circumference of the r 26 health ring and the r 20 TOTP ring. */
export const HEALTH_RING_LENGTH = 163.36;
export const TOTP_RING_LENGTH = 125.66;

/** stroke-dashoffset for a ring `fraction` (0–1) full, to two decimals. */
export function ringOffset(length: number, fraction: number): number {
    const clamped = Math.min(1, Math.max(0, fraction));

    return Number((length * (1 - clamped)).toFixed(2));
}
