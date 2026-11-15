import { motion } from 'motion/react';
import { useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import {
    barHeight,
    formatBarChip,
    maxAbs,
    monthLabels,
} from '@/components/ui/chart-utils';
import { ChartTooltip } from '@/components/ui/chart-tooltip';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { formatInr } from '@/lib/format';
import { cn } from '@/lib/utils';

export type BarChartDatum = {
    /** Month as "YYYY-MM-DD" (any day) or a Date. */
    date: string | Date;
    /** Integer paise; null = no figure yet (no bar). */
    value: number | null;
    /** Overrides for the x-axis label ("Oct") and the period ("Oct 2026"). */
    label?: string;
    period?: string;
};

export type BarChartProps = {
    data: readonly BarChartDatum[];
    /** What the bars measure, for each bar's label: "Oct 2026 profit ₹6,00,000". */
    metric?: string;
    /** The highlighted bar (lime, with its value chip). */
    selectedIndex?: number | null;
    onSelect?: (index: number) => void;
    /** Extra bars that also show their value chip. */
    labelledIndexes?: readonly number[];
    /** Start with this bar hovered (documentation and tests). */
    defaultHoveredIndex?: number | null;
    /** Card title (`cf-dot-title`) with the selected value on the right. */
    title?: ReactNode;
    /** Height in px of the tallest bar. */
    barMaxHeight?: number;
    loading?: boolean;
    emptyTitle?: ReactNode;
    emptyText?: ReactNode;
    /** Grow the bars in over duration-hero (off under reduced motion). */
    animate?: boolean;
    className?: string;
};

const GROW = { duration: 0.8, ease: [0.22, 1, 0.36, 1] as const };

const POS_CELL: CSSProperties = {
    flex: 1,
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'flex-end',
    gap: 6,
    height: '100%',
    position: 'relative',
};

const NEG_CELL: CSSProperties = {
    flex: 1,
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: 6,
};

const CHIP: CSSProperties = { height: 22, fontFamily: 'var(--font-mono)' };

/** Chip room above the tallest bar and under the deepest one. */
const CHIP_ROOM = 30;
const NEG_CHIP_ROOM = 28;

const SKELETON_HEIGHTS = [30, 50, 40, 64, 44];

/**
 * Bar chart: one value per period (profit by month). chart-ink pills on one axis,
 * negatives hanging below it in chart-negative, the selected bar in the lime
 * gradient with its value chip; hover or focus shows the rest in a tooltip.
 */
export function BarChart({
    data,
    metric = 'profit',
    selectedIndex = null,
    onSelect,
    labelledIndexes = [],
    defaultHoveredIndex = null,
    title,
    barMaxHeight = 100,
    loading = false,
    emptyTitle = 'No profit yet',
    emptyText = 'Bars appear after the first month closes.',
    animate = true,
    className,
}: BarChartProps) {
    const reduced = useReducedMotion();
    const [hovered, setHovered] = useState<number | null>(defaultHoveredIndex);
    const grow = animate && !reduced;

    const labels = data.map((d) => {
        const auto = monthLabels(d.date);

        return {
            short: d.label ?? auto.short,
            period: d.period ?? auto.period,
        };
    });
    const values = data.map((d) => d.value);
    const known = values.filter((v): v is number => v !== null);
    const top = maxAbs(known);
    const heights = values.map((v) =>
        v === null ? 0 : barHeight(v, top, barMaxHeight),
    );
    const isLabelled = (i: number) =>
        i === selectedIndex || labelledIndexes.includes(i);
    const hasNegative = known.some((v) => v < 0);
    const negRowHeight = hasNegative
        ? Math.max(
              ...values.map((v, i) =>
                  v !== null && v < 0
                      ? heights[i] + (isLabelled(i) ? NEG_CHIP_ROOM : 0)
                      : 0,
              ),
          ) + 4
        : 0;

    const header =
        title !== undefined ? (
            <div className="cf-kv mb-3">
                <h3 className="cf-dot-title t-title">{title}</h3>
                {selectedIndex !== null && values[selectedIndex] !== null && (
                    <span className="t-money-sm t-muted">
                        {labels[selectedIndex].period} ·{' '}
                        {formatInr(values[selectedIndex] ?? 0)}
                    </span>
                )}
            </div>
        ) : null;

    if (loading) {
        return (
            <div className={className} aria-busy="true">
                {header}
                <span className="cf-sr">Loading chart</span>
                <div
                    className="mt-2 flex items-end justify-between"
                    style={{ height: 70 }}
                >
                    {SKELETON_HEIGHTS.map((h, i) => (
                        <span
                            key={i}
                            className="cf-skel rounded-full"
                            style={{ width: 18, height: h }}
                        />
                    ))}
                </div>
            </div>
        );
    }

    if (known.length === 0) {
        return (
            <div className={className}>
                {header}
                <div
                    className="flex flex-col items-center justify-center gap-1 text-center"
                    style={{ minHeight: 70 }}
                >
                    <span className="t-label-strong">{emptyTitle}</span>
                    <span className="t-caption t-muted">{emptyText}</span>
                </div>
            </div>
        );
    }

    const ariaFor = (i: number) =>
        `${labels[i].period} ${metric} ${formatInr(values[i] ?? 0)}`;

    const bar = (i: number, negative: boolean) => (
        <motion.button
            type="button"
            className={cn(
                'cf-bar',
                negative && 'is-neg',
                hovered === i && 'is-hover',
            )}
            aria-label={ariaFor(i)}
            aria-pressed={
                onSelect
                    ? i === selectedIndex
                    : i === selectedIndex || undefined
            }
            initial={grow ? { height: 0 } : false}
            animate={{ height: heights[i] }}
            transition={grow ? GROW : { duration: 0 }}
            style={{ height: heights[i] }}
            onClick={() => onSelect?.(i)}
            onPointerEnter={() => setHovered(i)}
            onPointerLeave={() => setHovered((h) => (h === i ? null : h))}
            onFocus={() => setHovered(i)}
            onBlur={() => setHovered((h) => (h === i ? null : h))}
        />
    );

    const tip = (i: number, bottom: number) => (
        <ChartTooltip
            arrow
            title={labels[i].period}
            value={formatInr(values[i] ?? 0)}
            style={{ bottom, left: '50%', transform: 'translateX(-50%)' }}
        />
    );

    return (
        <div className={className}>
            {header}
            <div
                className="cf-bars"
                style={{ height: barMaxHeight + CHIP_ROOM }}
            >
                {values.map((v, i) => {
                    const showTip =
                        hovered === i && !isLabelled(i) && v !== null;

                    return (
                        <div key={i} style={POS_CELL}>
                            {showTip &&
                                (v >= 0 ? tip(i, heights[i] + 12) : tip(i, 12))}
                            {v !== null && v >= 0 && (
                                <>
                                    {isLabelled(i) && (
                                        <span
                                            className="cf-pill cf-pill--pos t-money-sm"
                                            style={CHIP}
                                        >
                                            {formatBarChip(v)}
                                        </span>
                                    )}
                                    {bar(i, false)}
                                </>
                            )}
                        </div>
                    );
                })}
            </div>
            {hasNegative && (
                <div className="cf-bars-neg" style={{ height: negRowHeight }}>
                    {values.map((v, i) => (
                        <div key={i} style={NEG_CELL}>
                            {v !== null && v < 0 && (
                                <>
                                    {bar(i, true)}
                                    {isLabelled(i) && (
                                        <span
                                            className="cf-pill cf-pill--neg"
                                            style={{ ...CHIP, fontSize: 12 }}
                                        >
                                            {formatBarChip(v)}
                                        </span>
                                    )}
                                </>
                            )}
                        </div>
                    ))}
                </div>
            )}
            <div
                className="cf-axis-x"
                style={{ padding: '0 6px' }}
                aria-hidden="true"
            >
                {labels.map((l, i) => (
                    <span key={i}>{l.short}</span>
                ))}
            </div>

            <table className="cf-sr">
                <caption>
                    {typeof title === 'string' ? title : `${metric} by month`}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Month</th>
                        <th scope="col">{metric}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((d, i) => (
                        <tr key={i}>
                            <th scope="row">{labels[i].period}</th>
                            <td>
                                {d.value === null ? '—' : formatInr(d.value)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
