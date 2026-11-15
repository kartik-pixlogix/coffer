import { motion } from 'motion/react';
import { useId, useState } from 'react';
import type { KeyboardEvent, PointerEvent, ReactNode } from 'react';
import {
    areaGeometry,
    areaPath,
    monthLabels,
    nearestIndex,
    niceScale,
    smoothPath,
} from '@/components/ui/chart-utils';
import { ChartLegend, ChartTooltip } from '@/components/ui/chart-tooltip';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { formatInr, formatInrCompact } from '@/lib/format';

export type AreaChartDatum = {
    /** Month as "YYYY-MM-DD" (any day) or a Date. */
    date: string | Date;
    /** Main series, integer paise. */
    value: number;
    /** Comparison series, integer paise. */
    comparison?: number | null;
    /** Overrides for the x-axis label ("Sep") and tooltip period ("Sep 2026"). */
    label?: string;
    period?: string;
};

export type AreaChartProps = {
    data: readonly AreaChartDatum[];
    /** Describes the chart for screen readers ("Monthly revenue and expenses, …"). */
    'aria-label': string;
    seriesName?: string;
    comparisonName?: string;
    /** Card title (`cf-dot-title`); renders the header row with the legend. */
    title?: ReactNode;
    /** Series totals in the legend ("Revenue ₹62.1L"). Default on. */
    legendTotals?: boolean;
    /** Plot height in px. */
    height?: number;
    /** Start with the crosshair on this point (documentation and tests). */
    defaultActiveIndex?: number | null;
    /** Draw in over duration-hero (off under reduced motion). */
    animate?: boolean;
    className?: string;
};

/** viewBox width; the SVG stretches to the card (preserveAspectRatio none). */
const VIEW_W = 520;
/** duration-hero, ease-out. */
const DRAW = { duration: 0.8, ease: [0.22, 1, 0.36, 1] as const };

/**
 * Area chart: one main series as a 2.5 px chart-ink line over a fading lime wash,
 * at most one comparison as a dashed chart-muted line, one y-axis. Hover or the
 * arrow keys move a crosshair that snaps to the nearest month.
 */
export function AreaChart({
    data,
    'aria-label': ariaLabel,
    seriesName = 'Revenue',
    comparisonName = 'Expenses',
    title,
    legendTotals = true,
    height = 160,
    defaultActiveIndex = null,
    animate = true,
    className,
}: AreaChartProps) {
    const uid = useId();
    const gradientId = `${uid}-wash`;
    const clipId = `${uid}-draw`;
    const liveId = `${uid}-live`;
    const reduced = useReducedMotion();
    const [active, setActive] = useState<number | null>(defaultActiveIndex);

    const hasComparison = data.some(
        (d) => d.comparison !== undefined && d.comparison !== null,
    );
    const scale = niceScale(
        data.flatMap((d) =>
            d.comparison === undefined || d.comparison === null
                ? [d.value]
                : [d.value, d.comparison],
        ),
    );
    const { xs, y } = areaGeometry(data.length, scale.max, VIEW_W, height);
    const labels = data.map((d) => {
        const auto = monthLabels(d.date);

        return {
            short: d.label ?? auto.short,
            period: d.period ?? auto.period,
        };
    });

    const line = smoothPath(data.map((d, i) => [xs[i], y(d.value)]));
    const comparisonLine = hasComparison
        ? smoothPath(data.map((d, i) => [xs[i], y(d.comparison ?? 0)] as const))
        : '';
    const wash = areaPath(line, VIEW_W, height);

    const total = data.reduce((sum, d) => sum + d.value, 0);
    const comparisonTotal = data.reduce(
        (sum, d) => sum + (d.comparison ?? 0),
        0,
    );

    const legend = hasComparison ? (
        <ChartLegend
            items={[
                {
                    label: seriesName,
                    value: legendTotals ? formatInrCompact(total) : undefined,
                },
                {
                    label: comparisonName,
                    swatch: 'line',
                    value: legendTotals
                        ? formatInrCompact(comparisonTotal)
                        : undefined,
                },
            ]}
        />
    ) : null;

    const pick = (event: PointerEvent<HTMLDivElement>) => {
        const box = event.currentTarget.getBoundingClientRect();

        if (box.width > 0 && data.length > 0) {
            setActive(
                nearestIndex(
                    (event.clientX - box.left) / box.width,
                    data.length,
                ),
            );
        }
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (data.length === 0) {
            return;
        }

        const last = data.length - 1;
        const current = active ?? last;
        const next =
            event.key === 'ArrowLeft'
                ? Math.max(0, current - 1)
                : event.key === 'ArrowRight'
                  ? Math.min(last, current + 1)
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? last
                      : null;

        if (event.key === 'Escape') {
            setActive(null);

            return;
        }

        if (next !== null) {
            event.preventDefault();
            setActive(next);
        }
    };

    const point = active !== null ? data[active] : undefined;
    const xPct = active !== null ? (xs[active] / VIEW_W) * 100 : 0;
    const describe = (i: number) => {
        const d = data[i];
        const parts = [`${seriesName} ${formatInr(d.value)}`];

        if (hasComparison) {
            parts.push(`${comparisonName} ${formatInr(d.comparison ?? 0)}`);
        }

        return `${labels[i].period}: ${parts.join(', ')}`;
    };

    const drawIn = animate && !reduced;

    return (
        <div className={className}>
            {title !== undefined && (
                <div className="cf-kv mb-3 flex-wrap">
                    <h3 className="cf-dot-title t-title">{title}</h3>
                    {legend}
                </div>
            )}
            {title === undefined && legend && (
                <div className="mb-3">{legend}</div>
            )}

            <div className="flex gap-2">
                <div
                    className="cf-axis flex flex-none flex-col justify-between text-right"
                    aria-hidden="true"
                    style={{ width: 34, height }}
                >
                    {scale.ticks.map((tick) => (
                        <span key={tick}>{formatInrCompact(tick)}</span>
                    ))}
                </div>
                <div className="relative min-w-0 flex-1">
                    <div
                        tabIndex={0}
                        role="group"
                        aria-label={`${ariaLabel}. Use the left and right arrow keys to read each month.`}
                        className="rounded-sm"
                        onPointerMove={pick}
                        onPointerDown={pick}
                        onPointerLeave={() => setActive(null)}
                        onFocus={() => setActive((a) => a ?? data.length - 1)}
                        onBlur={() => setActive(null)}
                        onKeyDown={onKeyDown}
                    >
                        <svg
                            role="img"
                            aria-label={ariaLabel}
                            viewBox={`0 0 ${VIEW_W} ${height}`}
                            preserveAspectRatio="none"
                            className="block w-full overflow-visible"
                            style={{ height }}
                        >
                            <defs>
                                <linearGradient
                                    id={gradientId}
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        className="cf-area-stop"
                                        style={{ stopOpacity: 0.6 }}
                                    />
                                    <stop
                                        offset="100%"
                                        className="cf-area-stop"
                                        style={{ stopOpacity: 0 }}
                                    />
                                </linearGradient>
                                {drawIn && (
                                    <clipPath id={clipId}>
                                        <motion.rect
                                            x={0}
                                            y={-height}
                                            height={height * 3}
                                            initial={{ width: 0 }}
                                            animate={{ width: VIEW_W }}
                                            transition={DRAW}
                                        />
                                    </clipPath>
                                )}
                            </defs>
                            <path
                                className="cf-baseline"
                                d={`M0 ${height - 0.5}H${VIEW_W}`}
                                vectorEffect="non-scaling-stroke"
                            />
                            <g
                                clipPath={
                                    drawIn ? `url(#${clipId})` : undefined
                                }
                            >
                                {wash && (
                                    <path
                                        d={wash}
                                        fill={`url(#${gradientId})`}
                                    />
                                )}
                                {comparisonLine && (
                                    <path
                                        className="cf-line-muted"
                                        vectorEffect="non-scaling-stroke"
                                        d={comparisonLine}
                                    />
                                )}
                                {line && (
                                    <path
                                        className="cf-line-ink"
                                        vectorEffect="non-scaling-stroke"
                                        d={line}
                                    />
                                )}
                            </g>
                            {active !== null && (
                                <path
                                    className="cf-crosshair"
                                    d={`M${xs[active].toFixed(1)} 0V${height}`}
                                    vectorEffect="non-scaling-stroke"
                                />
                            )}
                        </svg>
                    </div>

                    {point && active !== null && (
                        <>
                            {/* Markers sit outside the stretched SVG so they stay round. */}
                            <svg
                                className="pointer-events-none absolute overflow-visible"
                                width="12"
                                height="12"
                                aria-hidden="true"
                                style={{
                                    left: `calc(${xPct}% - 6px)`,
                                    top: y(point.value) - 6,
                                }}
                            >
                                <circle
                                    className="cf-marker"
                                    cx="6"
                                    cy="6"
                                    r="4"
                                />
                            </svg>
                            {hasComparison && (
                                <svg
                                    className="pointer-events-none absolute overflow-visible"
                                    width="12"
                                    height="12"
                                    aria-hidden="true"
                                    style={{
                                        left: `calc(${xPct}% - 6px)`,
                                        top: y(point.comparison ?? 0) - 6,
                                    }}
                                >
                                    <circle
                                        className="cf-marker"
                                        cx="6"
                                        cy="6"
                                        r="4"
                                        style={{ stroke: 'var(--chart-muted)' }}
                                    />
                                </svg>
                            )}
                            <ChartTooltip
                                title={labels[active].period}
                                rows={[
                                    {
                                        label: seriesName,
                                        value: formatInr(point.value),
                                        swatch: { color: 'var(--on-graphite)' },
                                    },
                                    ...(hasComparison
                                        ? [
                                              {
                                                  label: comparisonName,
                                                  value: formatInr(
                                                      point.comparison ?? 0,
                                                  ),
                                                  swatch: {
                                                      kind: 'line' as const,
                                                      color: 'var(--on-graphite-muted)',
                                                  },
                                              },
                                          ]
                                        : []),
                                ]}
                                style={
                                    xPct > 50
                                        ? {
                                              right: `calc(${100 - xPct}% + 12px)`,
                                              top: 4,
                                          }
                                        : {
                                              left: `calc(${xPct}% + 12px)`,
                                              top: 4,
                                          }
                                }
                            />
                        </>
                    )}

                    <div className="cf-axis-x" aria-hidden="true">
                        {labels.map((l, i) => (
                            <span key={i}>{l.short}</span>
                        ))}
                    </div>

                    <span id={liveId} className="cf-sr" aria-live="polite">
                        {active !== null ? describe(active) : ''}
                    </span>
                </div>
            </div>

            <table className="cf-sr">
                <caption>{ariaLabel}</caption>
                <thead>
                    <tr>
                        <th scope="col">Month</th>
                        <th scope="col">{seriesName}</th>
                        {hasComparison && <th scope="col">{comparisonName}</th>}
                    </tr>
                </thead>
                <tbody>
                    {data.map((d, i) => (
                        <tr key={i}>
                            <th scope="row">{labels[i].period}</th>
                            <td>{formatInr(d.value)}</td>
                            {hasComparison && (
                                <td>{formatInr(d.comparison ?? 0)}</td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
