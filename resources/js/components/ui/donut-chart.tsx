import { useState } from 'react';
import type {
    CSSProperties,
    KeyboardEvent,
    PointerEvent,
    ReactNode,
} from 'react';
import {
    BRAND_RAMP,
    PARTNER_PALETTE,
    angleFromTop,
    donutGradient,
    donutSegments,
    segmentAt,
} from '@/components/ui/chart-utils';
import type { DonutInput } from '@/components/ui/chart-utils';
import { ChartSwatch, ChartTooltip } from '@/components/ui/chart-tooltip';
import { formatInr, formatInrCompact, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';

export type DonutSlice = DonutInput;

export type DonutChartProps = {
    /** At most six slices; amounts in integer paise. */
    slices: readonly DonutSlice[];
    /** What the donut shows, starting the aria-label: "Revenue by partner". */
    label: string;
    /** partner-1…6 for people (in the order they were added); brand ramp for categories. */
    palette?: 'partner' | 'brand';
    /** The figure in the hole; defaults to the compact total (₹62.1L). */
    centre?: ReactNode;
    /** Caption under the figure. */
    caption?: ReactNode;
    /** Start with this slice's tooltip open (documentation and tests). */
    defaultActiveIndex?: number | null;
    className?: string;
};

/** The donut is 120 px with a 16 px ring (`cf-donut`, `cf-donut-hole` inset 16). */
const SIZE = 120;
const HOLE = 44;
/** The tooltip sits over the donut's top-right edge, beside the legend (preview). */
const TIP_POSITION: CSSProperties = { left: SIZE - 24, top: 6 };

const LEGEND: CSSProperties = {
    listStyle: 'none',
    margin: 0,
    padding: 0,
    flex: 1,
    minWidth: 0,
    display: 'flex',
    flexDirection: 'column',
    gap: 9,
    fontSize: 13,
};

/**
 * Donut chart (`cf-donut`): shares of one total, the total in the hole and a legend
 * that repeats every name and percentage. Hover a slice (or focus the donut and use
 * the arrow keys) to see its exact amount.
 */
export function DonutChart({
    slices,
    label,
    palette = 'partner',
    centre,
    caption = 'FY to date',
    defaultActiveIndex = null,
    className,
}: DonutChartProps) {
    const [active, setActive] = useState<number | null>(defaultActiveIndex);
    const segments = donutSegments(
        slices,
        palette === 'partner' ? PARTNER_PALETTE : BRAND_RAMP,
    );
    const total = segments.reduce((sum, s) => sum + s.value, 0);
    const aria = `${label}: ${segments
        .map((s) => `${s.name} ${s.percent}%`)
        .join(', ')}`;

    const pick = (event: PointerEvent<HTMLDivElement>) => {
        const box = event.currentTarget.getBoundingClientRect();
        const dx = event.clientX - box.left - box.width / 2;
        const dy = event.clientY - box.top - box.height / 2;
        const r = Math.hypot(dx, dy);

        if (r < (HOLE * box.width) / SIZE || r > box.width / 2) {
            setActive(null);

            return;
        }

        const index = segmentAt(segments, angleFromTop(dx, dy));

        setActive(index === -1 ? null : index);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const count = segments.length;

        if (count === 0) {
            return;
        }

        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((a) => (a === null ? 0 : (a + 1) % count));
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((a) =>
                a === null ? count - 1 : (a - 1 + count) % count,
            );
        } else if (event.key === 'Escape') {
            setActive(null);
        }
    };

    const current = active !== null ? segments[active] : undefined;

    return (
        <div
            className={cn('relative flex items-center', className)}
            style={{ gap: 16 }}
        >
            <div
                className="cf-donut"
                role="img"
                aria-label={aria}
                tabIndex={0}
                style={{ background: donutGradient(segments) }}
                onPointerMove={pick}
                onPointerDown={pick}
                onPointerLeave={() => setActive(null)}
                onKeyDown={onKeyDown}
                onBlur={() => setActive(null)}
            >
                <div className="cf-donut-hole">
                    <span className="t-figure-centre">
                        {centre ?? formatInrCompact(total)}
                    </span>
                    {caption && (
                        <span className="t-caption t-muted">{caption}</span>
                    )}
                </div>
            </div>
            <ul style={LEGEND}>
                {segments.map((s) => (
                    <li key={s.name} className="flex items-center gap-2">
                        <ChartSwatch color={s.color} />
                        <span className="flex-1">{s.name}</span>
                        <span className="t-money-sm t-muted">{s.percent}%</span>
                    </li>
                ))}
            </ul>
            {current && (
                <ChartTooltip
                    title={current.name}
                    value={`${formatInr(current.value)} · ${formatPercent(current.share)}`}
                    style={TIP_POSITION}
                />
            )}
            <span className="cf-sr" aria-live="polite">
                {current
                    ? `${current.name}: ${formatInr(current.value)}, ${formatPercent(current.share)}`
                    : ''}
            </span>
        </div>
    );
}
