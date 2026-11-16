import { cn } from '@/lib/utils';
import type { StrengthLook } from '@/components/vault/form/strength';

/** The 4 `cf-meter-dots` (decorative: the label next to them says the same). */
export function StrengthDots({
    look,
    className,
}: {
    look: StrengthLook;
    className?: string;
}) {
    return (
        <div className={cn('cf-meter-dots', className)} aria-hidden="true">
            {look.dots.map((background, i) => (
                <span key={i} style={{ background }} />
            ))}
        </div>
    );
}

/** "Strong" in the strength colour, `t-caption` semibold. */
export function StrengthLabel({ look }: { look: StrengthLook }) {
    return (
        <span className="t-caption vf-strength">
            <span className={look.ink}>{look.label}</span>
        </span>
    );
}
