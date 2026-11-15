import { HEALTH_RING_LENGTH, ringOffset } from '@/components/ui/chart-utils';
import { cn } from '@/lib/utils';

export type HealthRingProps = {
    /** Score out of 100. */
    score: number;
    /** `dark` = lime bar on a graphite card; `light` = ink bar on surface. */
    tone?: 'dark' | 'light';
    /** `sm` 60 px (stroke 6), `lg` 120 px with "of 100" under the score (stroke 5). */
    size?: 'sm' | 'lg';
    /** What the score is of, for the label ("Vault health 82 out of 100"). */
    label?: string;
    className?: string;
};

/** Below this the ring turns warning. */
export const HEALTH_WARN_BELOW = 60;

/**
 * Health score ring (`cf-ring`): the vault's security score out of 100, number in
 * the middle (hidden from screen readers; the SVG label carries it).
 */
export function HealthRing({
    score,
    tone = 'light',
    size = 'sm',
    label = 'Vault health',
    className,
}: HealthRingProps) {
    const value = Math.round(Math.min(100, Math.max(0, score)));
    const px = size === 'lg' ? 120 : 60;
    const stroke = size === 'lg' ? 5 : 6;
    const offset = ringOffset(HEALTH_RING_LENGTH, value / 100);

    return (
        <span
            className={cn(
                'cf-ring',
                tone === 'dark' ? 'cf-ring--dark' : 'cf-ring--onlight',
                value < HEALTH_WARN_BELOW && 'is-warn',
                className,
            )}
        >
            <svg
                width={px}
                height={px}
                viewBox="0 0 60 60"
                role="img"
                aria-label={`${label} ${value} out of 100`}
            >
                <circle
                    className="cf-ring-track"
                    cx="30"
                    cy="30"
                    r="26"
                    strokeWidth={stroke}
                />
                <circle
                    className="cf-ring-bar"
                    cx="30"
                    cy="30"
                    r="26"
                    strokeWidth={stroke}
                    strokeDasharray={HEALTH_RING_LENGTH}
                    strokeDashoffset={offset}
                    transform="rotate(-90 30 30)"
                />
            </svg>
            {size === 'lg' ? (
                <span className="cf-ring-label flex-col" aria-hidden="true">
                    <span
                        className="t-figure-centre"
                        style={{ fontSize: 30, lineHeight: '32px' }}
                    >
                        {value}
                    </span>
                    <span className="t-caption t-muted">of 100</span>
                </span>
            ) : tone === 'dark' ? (
                <span
                    className="cf-ring-label font-display text-[18px] font-medium"
                    aria-hidden="true"
                >
                    {value}
                </span>
            ) : (
                <span className="cf-ring-label t-title" aria-hidden="true">
                    {value}
                </span>
            )}
        </span>
    );
}
