import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';

export type CoverProps = {
    name?: string;
    tagline?: string;
    /** The name's element: `h1` on a page of its own, `p` inside another page. */
    as?: 'h1' | 'h2' | 'p';
    className?: string;
};

/** Tiles round at radius-lg (the attribute value is the same 20). */
const TILE: CSSProperties = { rx: 'var(--radius-lg)' };

/** Bars on one baseline: [x, y, height]; the selected bar is lime. */
const BARS: readonly (readonly [number, number, number])[] = [
    [104, 236, 32],
    [134, 220, 48],
    [164, 228, 40],
    [194, 204, 64],
    [254, 180, 88],
    [284, 196, 72],
    [314, 172, 96],
    [344, 212, 56],
    [374, 188, 80],
    [404, 204, 64],
    [434, 164, 104],
];

/**
 * The brand cover: the Coffer name in display 120 px with its tagline, beside a
 * corner of the card grid drawn in tokens (lime slab, line-strong tile, the
 * graphite pill, a row of chart-bar pills with the selected one in lime).
 */
export function Cover({
    name = 'Coffer',
    tagline = 'Every company, partner and password in one ledger.',
    as: Name = 'h1',
    className,
}: CoverProps) {
    return (
        <div
            className={cn(
                'relative overflow-hidden bg-surface text-ink',
                className,
            )}
            style={{ height: 288, fontFamily: 'var(--font-sans)' }}
        >
            <div
                className="absolute top-0 overflow-hidden max-md:hidden"
                style={{ left: 480, width: 480, height: 288 }}
                aria-hidden="true"
            >
                <svg
                    viewBox="0 0 480 288"
                    width="480"
                    height="288"
                    className="block overflow-hidden"
                >
                    <rect
                        className="fill-lime"
                        style={TILE}
                        x="16"
                        y="-28"
                        width="176"
                        height="200"
                        rx="20"
                    />
                    <rect
                        className="fill-line-strong"
                        style={TILE}
                        x="16"
                        y="188"
                        width="72"
                        height="80"
                        rx="20"
                    />
                    <rect
                        className="fill-graphite"
                        x="208"
                        y="24"
                        width="288"
                        height="112"
                        rx="56"
                    />
                    {BARS.slice(0, 4).map(([x, y, h]) => (
                        <rect
                            key={x}
                            className="fill-chart-ink"
                            x={x}
                            y={y}
                            width="22"
                            height={h}
                            rx="11"
                        />
                    ))}
                    <rect
                        className="fill-lime"
                        x="224"
                        y="156"
                        width="22"
                        height="112"
                        rx="11"
                    />
                    {BARS.slice(4).map(([x, y, h]) => (
                        <rect
                            key={x}
                            className="fill-chart-ink"
                            x={x}
                            y={y}
                            width="22"
                            height={h}
                            rx="11"
                        />
                    ))}
                </svg>
            </div>
            <div
                className="absolute bottom-5 left-10"
                style={{ maxWidth: 440 }}
            >
                <Name
                    className="m-0 font-display font-semibold text-ink"
                    style={{
                        fontSize: 120,
                        lineHeight: 0.92,
                        letterSpacing: '-0.02em',
                    }}
                >
                    {name}
                </Name>
                <p
                    className="text-ink-secondary"
                    style={{
                        margin: 'var(--space-4) 0 0 6px',
                        fontSize: 14,
                        lineHeight: '20px',
                        fontWeight: 400,
                    }}
                >
                    {tagline}
                </p>
            </div>
        </div>
    );
}
