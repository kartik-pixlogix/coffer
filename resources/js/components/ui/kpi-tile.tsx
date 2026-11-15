import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { HTMLAttributes, KeyboardEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Hours, Money } from '@/components/ui/money';
import { cn } from '@/lib/utils';

export type KpiTileProps = {
    title: ReactNode;
    /** Glyph in the lime chip before the title. */
    icon: LucideIcon;
    /** Top-right slot: a TrendChip or a StatusPill. */
    aside?: ReactNode;
    /** The figure in paise: `<Money size="lg" countUp>`. */
    paise?: number;
    /** Or hours: `<Hours size="lg" countUp>`. */
    hours?: number;
    /** Or any custom figure. */
    value?: ReactNode;
    /** Count the figure up on first load (default on; off under reduced motion). */
    countUp?: boolean;
    /** One line of context under the figure. */
    caption?: ReactNode;
    /** Turns the caption into a link ("Review in Expenses"). */
    captionHref?: string;
    /** Rings the tile when it filters the page. */
    selected?: boolean;
    /** Makes the tile a toggle that filters the page (`aria-pressed` = selected). */
    onSelect?: () => void;
    loading?: boolean;
    /** Couldn't load: the message shown instead of the figure. Never show ₹0. */
    error?: string | boolean;
    onRetry?: () => void;
    headingLevel?: 'h2' | 'h3' | 'h4';
} & Omit<HTMLAttributes<HTMLDivElement>, 'title' | 'onSelect'>;

/**
 * KPI tile (`cf-kpi`): one headline number, its trend or status top-right and one
 * line of context underneath. Rows of three to six.
 */
export function KpiTile({
    title,
    icon,
    aside,
    paise,
    hours,
    value,
    countUp = true,
    caption,
    captionHref,
    selected = false,
    onSelect,
    loading = false,
    error,
    onRetry,
    headingLevel: Heading = 'h3',
    className,
    ...rest
}: KpiTileProps) {
    const classes = cn(
        'cf-kpi',
        selected && 'is-selected',
        loading && 'is-loading',
        className,
    );

    if (loading) {
        return (
            <div className={classes} aria-busy="true" {...rest}>
                <div className="cf-kpi-head">
                    <span
                        className="cf-skel rounded-full"
                        style={{ width: 140, height: 32 }}
                    />
                </div>
                <span
                    className="cf-skel"
                    style={{ width: '70%', height: 36 }}
                />
                <span
                    className="cf-skel cf-skel--line"
                    style={{ width: '50%' }}
                />
                <span className="cf-sr">Loading</span>
            </div>
        );
    }

    const head = (
        <div className="cf-kpi-head">
            <Heading className="cf-kpi-title">
                <span className="cf-kpi-ic">
                    <Icon icon={icon} size={16} strokeWidth={1.75} />
                </span>
                {title}
            </Heading>
            {!error && aside}
        </div>
    );

    if (error) {
        return (
            <div className={classes} {...rest}>
                {head}
                <div className="cf-errtext text-[13px]" role="alert">
                    <Icon icon={TriangleAlert} size={14} />
                    {typeof error === 'string'
                        ? error
                        : 'Couldn’t load totals.'}
                </div>
                {onRetry && (
                    <Button size="sm" className="self-start" onClick={onRetry}>
                        Try again
                    </Button>
                )}
            </div>
        );
    }

    const figure =
        value ??
        (paise !== undefined ? (
            <Money
                paise={paise}
                size="lg"
                countUp={countUp}
                className="gap-1"
            />
        ) : hours !== undefined ? (
            <Hours
                hours={hours}
                size="lg"
                countUp={countUp}
                className="gap-1"
            />
        ) : null);

    const captionNode =
        caption === undefined ? null : captionHref ? (
            <Link href={captionHref} className="t-caption font-medium">
                {caption}
            </Link>
        ) : typeof caption === 'string' ? (
            <span className="t-caption t-muted">{caption}</span>
        ) : (
            caption
        );

    const interactive = onSelect
        ? {
              role: 'button',
              tabIndex: 0,
              'aria-pressed': selected,
              onClick: onSelect,
              onKeyDown: (event: KeyboardEvent<HTMLDivElement>) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                      event.preventDefault();
                      onSelect();
                  }
              },
          }
        : {};

    return (
        <div
            className={cn(classes, onSelect && 'cursor-pointer')}
            {...interactive}
            {...rest}
        >
            {head}
            <div className="cf-kpi-value">{figure}</div>
            {captionNode}
        </div>
    );
}
