import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export type ProgressVariant = 'default' | 'lime' | 'pos' | 'warn' | 'neg';

export type ProgressBarProps = {
    value: number;
    min?: number;
    max?: number;
    /** Names the bar for screen readers ("Loan repaid"). Required. */
    'aria-label': string;
    /** Readable value, e.g. "₹1,20,000 of ₹3,00,000". */
    'aria-valuetext'?: string;
    /** default chart-ink, lime (equity), warn (80%+ of a limit), neg (over). */
    variant?: ProgressVariant;
    /** 4 px, for lists. */
    thin?: boolean;
    loading?: boolean;
    /** Fill colour token, e.g. `var(--partner-2)` for an equity split (Partners.dc.html). */
    fill?: string;
} & Omit<HTMLAttributes<HTMLDivElement>, 'children' | 'role'>;

/** Percent filled, 0–100, for a value within [min, max]. */
export function progressPercent(value: number, min = 0, max = 100): number {
    if (max <= min) {
        return 0;
    }

    return Math.min(100, Math.max(0, ((value - min) / (max - min)) * 100));
}

/**
 * Progress bar (`cf-progress`): a known part of a whole. Always write the numbers
 * beside it; the bar only illustrates them. Values past `max` fill the bar.
 */
export function ProgressBar({
    value,
    min = 0,
    max = 100,
    variant = 'default',
    thin = false,
    loading = false,
    fill,
    className,
    'aria-label': ariaLabel,
    ...rest
}: ProgressBarProps) {
    const classes = cn(
        'cf-progress',
        variant !== 'default' && `cf-progress--${variant}`,
        thin && 'cf-progress--thin',
        className,
    );

    if (loading) {
        return (
            <div className={classes} aria-busy="true" aria-label={ariaLabel}>
                <span
                    className="cf-skel rounded-full"
                    style={{ width: '100%', height: thin ? 4 : 8 }}
                />
            </div>
        );
    }

    const pct = progressPercent(value, min, max);
    const now = Math.min(max, Math.max(min, value));

    return (
        <div
            className={classes}
            role="progressbar"
            aria-label={ariaLabel}
            aria-valuenow={Number(now.toFixed(2))}
            aria-valuemin={min}
            aria-valuemax={max}
            {...rest}
        >
            <span
                style={{
                    width: `${Number(pct.toFixed(2))}%`,
                    ...(fill ? { background: fill } : {}),
                }}
            />
        </div>
    );
}
