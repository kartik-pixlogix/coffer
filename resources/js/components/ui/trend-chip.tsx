import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import type { HTMLAttributes, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';

export type TrendDirection = 'up' | 'down' | 'none';

/** Colour meaning: up is good for income (positive); a rise in costs stays neutral. */
export type TrendTone = 'positive' | 'negative' | 'neutral';

const TONE_CLASS: Record<TrendTone, string> = {
    positive: 'cf-trend--up',
    negative: 'cf-trend--down',
    neutral: 'cf-trend--flat',
};

const ARROW = { up: ArrowUpRight, down: ArrowDownRight, none: Minus };

export type TrendChipProps = {
    /**
     * Percent change (6 = 6%). Sets the arrow from its sign, writes "6%" and,
     * with `comparedTo`, the label "Up 6% on September".
     */
    percent?: number;
    /** The previous period, for the aria-label ("September"). */
    comparedTo?: string;
    /** Arrow direction; defaults from `percent`. */
    direction?: TrendDirection;
    /** Colour; defaults to positive for up, negative for down. Pass neutral for costs. */
    tone?: TrendTone;
    /** Text after the arrow; defaults to the percent. */
    children?: ReactNode;
    'aria-label'?: string;
} & Omit<HTMLAttributes<HTMLSpanElement>, 'children'>;

/** Rounds to whole percent unless the change is small ("0.4%"). */
function percentText(percent: number): string {
    const abs = Math.abs(percent);

    return formatPercent(abs, abs < 1 && abs > 0 ? 1 : 0);
}

/** Accessible comparison for a percent change: "Up 6% on September", "No change". */
export function trendLabel(percent: number, comparedTo?: string): string {
    if (percent === 0) {
        return comparedTo ? `No change on ${comparedTo}` : 'No change';
    }

    const word = percent > 0 ? 'Up' : 'Down';
    const on = comparedTo ? ` on ${comparedTo}` : '';

    return `${word} ${percentText(percent)}${on}`;
}

/**
 * Trend chip (`cf-trend`): change against the previous period, with an arrow so the
 * direction never relies on colour alone.
 */
export function TrendChip({
    percent,
    comparedTo,
    direction,
    tone,
    className,
    children,
    'aria-label': ariaLabel,
    ...rest
}: TrendChipProps) {
    const dir: TrendDirection =
        direction ??
        (percent === undefined || percent > 0
            ? 'up'
            : percent < 0
              ? 'down'
              : 'none');
    const finalTone: TrendTone =
        tone ??
        (dir === 'up' ? 'positive' : dir === 'down' ? 'negative' : 'neutral');
    const label =
        ariaLabel ??
        (percent !== undefined ? trendLabel(percent, comparedTo) : undefined);

    return (
        <span
            className={cn('cf-trend', TONE_CLASS[finalTone], className)}
            aria-label={label}
            {...rest}
        >
            <Icon icon={ARROW[dir]} size={12} strokeWidth={2} />
            {children ?? (percent !== undefined ? percentText(percent) : null)}
        </span>
    );
}
