import type { CSSProperties } from 'react';
import { COUNT_UP_MS, useCountUp } from '@/hooks/use-count-up';
import {
    formatHours,
    formatIndianNumber,
    formatInr,
    formatPaiseDigits,
    MINUS,
} from '@/lib/format';
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
    // Whole rupees while counting, the exact figure at the end (R6).
    const shown = useCountUp(paise, countUp, COUNT_UP_MS, 100);
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
        const digits = formatPaiseDigits(paise, decimals);

        return (
            <span
                className={cn(
                    'inline-flex items-baseline',
                    toneClass,
                    className,
                )}
                // The final figure's length sizes the KPI value to fit its tile (D126).
                style={{ '--cf-money-len': digits.length } as CSSProperties}
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
                {/* A span can't carry aria-label: screen readers get the final figure here. */}
                {countUp && <span className="cf-sr">{label}</span>}
            </span>
        );
    }

    return (
        <span className={cn(SIZE_CLASS[size], toneClass, className)}>
            {countUp ? (
                <>
                    <span aria-hidden="true">
                        {formatInr(shown, { decimals, signed })}
                    </span>
                    <span className="cf-sr">{label}</span>
                </>
            ) : (
                label
            )}
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
                style={
                    {
                        '--cf-money-len': formatHours(hours).length,
                    } as CSSProperties
                }
            >
                <span className="t-money-lg" aria-hidden={countUp || undefined}>
                    {text}
                </span>
                <span className="t-money-unit" aria-hidden="true">
                    h
                </span>
                {/* A span can't carry aria-label: screen readers get the final figure here. */}
                <span className="cf-sr">
                    {countUp ? `${formatHours(hours)} hours` : ' hours'}
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

/**
 * A figure with its unit ("57.33 h") as the figure plus a tight unit span: a full
 * space in the mono face reads as a gap between the number and its "h".
 */
export function UnitText({ text }: { text: string }) {
    const match = /^(.*\d) (h)$/.exec(text);

    if (match === null) {
        return <>{text}</>;
    }

    return (
        <>
            {match[1]}
            <span className="cf-unit">{match[2]}</span>
        </>
    );
}

export type CountProps = {
    value: number;
    countUp?: boolean;
    className?: string;
};

/**
 * A count shown as a KPI figure ("Entries 28", "Open loans 2"): the same large mono
 * figure as money (`t-money-lg`), Indian grouping, sized to its tile like money.
 */
export function Count({ value, countUp = false, className }: CountProps) {
    const shown = useCountUp(value, countUp);
    const label = formatIndianNumber(value);

    return (
        <span
            className={cn('t-money-lg', className)}
            style={{ '--cf-money-len': label.length } as CSSProperties}
        >
            {countUp ? (
                <>
                    <span aria-hidden="true">{formatIndianNumber(shown)}</span>
                    <span className="cf-sr">{label}</span>
                </>
            ) : (
                label
            )}
        </span>
    );
}
