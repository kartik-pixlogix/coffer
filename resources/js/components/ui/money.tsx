import { useCountUp } from '@/hooks/use-count-up';
import { formatHours, formatInr, formatPaiseDigits, MINUS } from '@/lib/format';
import type { MoneyFormatOptions } from '@/lib/format';
import { cn } from '@/lib/utils';

type MoneySize = 'lg' | 'md' | 'sm';

const SIZE_CLASS: Record<Exclude<MoneySize, 'lg'>, string> = {
    md: 't-money',
    sm: 't-money-sm',
};

export type MoneyProps = {
    /** Integer paise. */
    paise: number;
    /**
     * lg = KPI figure (`t-money-unit` ₹ + `t-money-lg`), md = tables, forms, banners
     * (`t-money`), sm = legends, tooltips, chips (`t-money-sm`).
     */
    size?: MoneySize;
    /** Colour negatives with `t-neg` (default) and positives with `t-pos` when asked. */
    tone?: 'auto' | 'positive' | 'none';
    countUp?: boolean;
    decimals?: MoneyFormatOptions['decimals'];
    signed?: boolean;
    className?: string;
};

/**
 * Every amount in Coffer: IBM Plex Mono, tabular figures, Indian grouping,
 * a true minus (−₹2,66,500) for negatives.
 */
export function Money({
    paise,
    size = 'md',
    tone = 'auto',
    countUp = false,
    decimals = 'auto',
    signed = false,
    className,
}: MoneyProps) {
    const shown = useCountUp(paise, countUp);
    const toneClass =
        tone === 'none'
            ? undefined
            : paise < 0
              ? 't-neg'
              : tone === 'positive' && paise > 0
                ? 't-pos'
                : undefined;
    const label = formatInr(paise, { decimals, signed });

    if (size === 'lg') {
        const sign = shown < 0 ? MINUS : signed && shown > 0 ? '+' : '';

        return (
            <span
                className={cn(
                    'inline-flex items-baseline',
                    toneClass,
                    className,
                )}
                aria-label={countUp ? label : undefined}
            >
                <span
                    className="t-money-unit"
                    aria-hidden={countUp || undefined}
                >
                    {sign}₹
                </span>
                <span className="t-money-lg" aria-hidden={countUp || undefined}>
                    {formatPaiseDigits(shown, decimals)}
                </span>
            </span>
        );
    }

    return (
        <span
            className={cn(SIZE_CLASS[size], toneClass, className)}
            aria-label={countUp ? label : undefined}
        >
            {formatInr(shown, { decimals, signed })}
        </span>
    );
}

export type HoursProps = {
    hours: number;
    size?: MoneySize;
    countUp?: boolean;
    className?: string;
};

/** Hours to one decimal with Indian grouping; `lg` puts the "h" unit after the figure. */
export function Hours({
    hours,
    size = 'md',
    countUp = false,
    className,
}: HoursProps) {
    const tenths = useCountUp(Math.round(hours * 10), countUp);
    const text = formatHours(tenths / 10);

    if (size === 'lg') {
        return (
            <span
                className={cn('inline-flex items-baseline', className)}
                aria-label={countUp ? `${formatHours(hours)} hours` : undefined}
            >
                <span className="t-money-lg" aria-hidden={countUp || undefined}>
                    {text}
                </span>
                <span className="t-money-unit" aria-hidden="true">
                    h
                </span>
            </span>
        );
    }

    return (
        <span className={cn(SIZE_CLASS[size], className)}>
            {text}
            <span className="cf-sr"> hours</span>
        </span>
    );
}
