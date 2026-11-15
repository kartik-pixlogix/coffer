import { Fragment } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SwatchKind = 'dot' | 'line' | 'sq';

export type ChartSwatchProps = {
    kind?: SwatchKind;
    /** Token colour, e.g. "var(--chart-1)". Defaults to chart-ink (dot) or chart-muted (line). */
    color?: string;
    className?: string;
};

/** `cf-swatch`: the dot, dashed line or square that marks a series. */
export function ChartSwatch({
    kind = 'dot',
    color,
    className,
}: ChartSwatchProps) {
    const style: CSSProperties | undefined = color
        ? kind === 'line'
            ? { borderColor: color }
            : {
                  background: color,
                  // Lime needs an edge to show on light grounds.
                  boxShadow:
                      color === 'var(--lime)'
                          ? 'inset 0 0 0 1px var(--line-strong)'
                          : undefined,
              }
        : undefined;

    return (
        <span
            className={cn(
                'cf-swatch',
                kind === 'line' && 'cf-swatch--line',
                kind === 'sq' && 'cf-swatch--sq',
                className,
            )}
            style={style}
            aria-hidden="true"
        />
    );
}

export type ChartTooltipRow = {
    label: ReactNode;
    /** Exact value, e.g. "₹10,85,000" (mono `t-money-sm`). */
    value: ReactNode;
    swatch?: ChartSwatchProps;
    /** A totals line above a divider ("Profit"). */
    total?: boolean;
};

export type ChartTooltipProps = {
    /** The period on top in on-graphite-muted ("Sep 2026"). */
    title: ReactNode;
    /** One exact value under the title (single-series charts). */
    value?: ReactNode;
    /** Or one row per series. */
    rows?: readonly ChartTooltipRow[];
    /** In-flow (documentation, legends) rather than absolutely positioned. */
    static?: boolean;
    /** Points down at a single bar. */
    arrow?: boolean;
    className?: string;
    style?: CSSProperties;
};

const DIVIDER: CSSProperties = {
    borderTop: '1px solid rgba(255,255,255,0.16)',
    marginTop: 4,
    paddingTop: 4,
};

/**
 * The graphite tooltip every chart uses on hover (`cf-tip`): period on top, exact
 * ₹ values in mono. Decorative for screen readers; charts expose their numbers in
 * a table and a live region instead.
 */
export function ChartTooltip({
    title,
    value,
    rows,
    static: isStatic = false,
    arrow = false,
    className,
    style,
}: ChartTooltipProps) {
    return (
        <span
            className={cn(
                'cf-tip',
                isStatic && 'cf-tip--static',
                arrow && 'cf-tip--arrow',
                className,
            )}
            style={style}
        >
            <span className="cf-tip-muted">{title}</span>
            {value !== undefined && (
                <>
                    <br />
                    <span className="t-money-sm">{value}</span>
                </>
            )}
            {rows?.map((row, i) => (
                <span
                    key={i}
                    className="cf-tip-row"
                    style={
                        row.total
                            ? DIVIDER
                            : i === 0
                              ? { marginTop: 4 }
                              : undefined
                    }
                >
                    {row.swatch ? (
                        <span className="cf-row" style={{ gap: 6 }}>
                            <ChartSwatch {...row.swatch} />
                            {row.label}
                        </span>
                    ) : (
                        <span>{row.label}</span>
                    )}
                    <span className="t-money-sm">{row.value}</span>
                </span>
            ))}
        </span>
    );
}

export type ChartLegendItem = {
    label: ReactNode;
    swatch?: SwatchKind;
    color?: string;
    /** A figure after the label, e.g. the series total "₹62.1L". */
    value?: ReactNode;
};

export type ChartLegendProps = {
    items: readonly ChartLegendItem[];
    className?: string;
    'aria-label'?: string;
};

/**
 * `cf-legend`: shown above the plot whenever a chart has two or more series. The
 * text stays ink-secondary; only the swatch carries colour.
 */
export function ChartLegend({
    items,
    className,
    'aria-label': ariaLabel,
}: ChartLegendProps) {
    return (
        <ul className={cn('cf-legend', className)} aria-label={ariaLabel}>
            {items.map((item, i) => (
                <li key={i}>
                    <ChartSwatch kind={item.swatch} color={item.color} />
                    {item.label}
                    {item.value !== undefined && (
                        <Fragment>
                            {' '}
                            <span
                                className="t-money-sm"
                                style={{ color: 'var(--ink)' }}
                            >
                                {item.value}
                            </span>
                        </Fragment>
                    )}
                </li>
            ))}
        </ul>
    );
}
