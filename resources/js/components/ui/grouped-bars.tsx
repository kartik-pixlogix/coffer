import { motion } from 'motion/react';
import { Fragment } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { SERIES_PALETTE, barHeight, maxAbs } from '@/components/ui/chart-utils';
import { ChartLegend } from '@/components/ui/chart-tooltip';
import type { ChartLegendItem } from '@/components/ui/chart-tooltip';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { formatInr, formatInrCompact } from '@/lib/format';

export type GroupedBarsGroup = {
    /** Group name on the axis ("Aarav"). */
    label: string;
    /** One value per series, integer paise; null = no bar. */
    values: readonly (number | null)[];
};

export type GroupedBarsProps = {
    /** Two or three measures, coloured chart-1, chart-2, chart-3 in order. */
    series: readonly string[];
    groups: readonly GroupedBarsGroup[];
    /** Card title (`cf-dot-title`); the legend sits beside it. */
    title?: ReactNode;
    /** Legend label for values below zero. */
    negativeLabel?: string;
    /** Height in px of the tallest bar. */
    barMaxHeight?: number;
    animate?: boolean;
    className?: string;
};

const GROW = { duration: 0.8, ease: [0.22, 1, 0.36, 1] as const };

const GROUP: CSSProperties = {
    flex: 1,
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'flex-end',
    gap: 4,
    height: '100%',
};

const GROUP_NEG: CSSProperties = {
    flex: 1,
    display: 'flex',
    justifyContent: 'center',
    alignItems: 'flex-start',
    gap: 4,
};

const BAR: CSSProperties = {
    width: 16,
    borderRadius: 'var(--radius-full)',
    border: 0,
    padding: 0,
};

const SPACER: CSSProperties = { width: 16 };

/** Room under the deepest negative bar (the design's 34 px row for a 15 px bar). */
const NEG_ROOM = 19;

/**
 * Grouped bars: two or three measures across partners or sides on one shared axis.
 * 16 px bars 4 px apart; a value below zero hangs under the axis in chart-negative
 * and is written under the group name. The legend always sits above the plot.
 */
export function GroupedBars({
    series,
    groups,
    title,
    negativeLabel,
    barMaxHeight = 100,
    animate = true,
    className,
}: GroupedBarsProps) {
    const reduced = useReducedMotion();
    const grow = animate && !reduced;

    const all = groups.flatMap((g) =>
        g.values.filter((v): v is number => v !== null),
    );
    const top = maxAbs(all);
    const heightOf = (v: number) => barHeight(v, top, barMaxHeight, 2);
    const negatives = all.filter((v) => v < 0);
    const hasNegative = negatives.length > 0;
    const negRow = hasNegative
        ? Math.max(...negatives.map(heightOf)) + NEG_ROOM
        : 0;

    const legendItems: ChartLegendItem[] = series.map((name, i) => ({
        label: name,
        swatch: 'sq',
        color: SERIES_PALETTE[i],
    }));

    if (hasNegative) {
        const lastSeries = series[series.length - 1] ?? 'Value';

        legendItems.push({
            label: negativeLabel ?? `${lastSeries} below zero`,
            swatch: 'sq',
            color: 'var(--chart-negative)',
        });
    }

    const legend = <ChartLegend items={legendItems} />;

    const bar = (group: GroupedBarsGroup, s: number, value: number) => (
        <motion.button
            key={s}
            type="button"
            aria-label={`${group.label} ${series[s].toLowerCase()} ${formatInr(value)}`}
            initial={grow ? { height: 0 } : false}
            animate={{ height: heightOf(value) }}
            transition={grow ? GROW : { duration: 0 }}
            style={{
                ...BAR,
                height: heightOf(value),
                background:
                    value < 0 ? 'var(--chart-negative)' : SERIES_PALETTE[s],
            }}
        />
    );

    return (
        <div className={className}>
            {title !== undefined ? (
                <div className="cf-kv mb-3 flex-wrap">
                    <h3 className="cf-dot-title t-title">{title}</h3>
                    {legend}
                </div>
            ) : (
                <div className="mb-3">{legend}</div>
            )}

            <div
                className="flex"
                style={{
                    height: barMaxHeight + 20,
                    borderBottom: '1px solid var(--line-strong)',
                    padding: '0 6px',
                }}
            >
                {groups.map((group) => (
                    <div key={group.label} style={GROUP}>
                        {group.values.map((v, s) =>
                            v === null ? null : v >= 0 ? (
                                bar(group, s, v)
                            ) : (
                                <span key={s} style={SPACER} />
                            ),
                        )}
                    </div>
                ))}
            </div>

            {hasNegative && (
                <div
                    className="flex"
                    style={{ height: negRow, padding: '0 6px' }}
                >
                    {groups.map((group) => {
                        const groupHasNegative = group.values.some(
                            (v) => v !== null && v < 0,
                        );

                        return (
                            <div key={group.label} style={GROUP_NEG}>
                                {groupHasNegative &&
                                    group.values.map((v, s) =>
                                        v === null ? null : v < 0 ? (
                                            bar(group, s, v)
                                        ) : (
                                            <span key={s} style={SPACER} />
                                        ),
                                    )}
                            </div>
                        );
                    })}
                </div>
            )}

            <div
                className="cf-axis-x"
                style={{ justifyContent: 'space-around' }}
                aria-hidden="true"
            >
                {groups.map((group) => (
                    <span key={group.label}>
                        {group.label}
                        {group.values.map((v, s) =>
                            v !== null && v < 0 ? (
                                <Fragment key={s}>
                                    {' '}
                                    <span className="t-money-sm t-neg">
                                        {formatInrCompact(v)}
                                    </span>
                                </Fragment>
                            ) : null,
                        )}
                    </span>
                ))}
            </div>
        </div>
    );
}
