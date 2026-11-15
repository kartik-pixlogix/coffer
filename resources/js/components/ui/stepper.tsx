import { Check } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type StepperProps = {
    /** Names the flow ("Create company", "Bulk import"). */
    'aria-label': string;
    /** Step labels in order. */
    steps: ReactNode[];
    /** Index (0-based) of the step the person is on; earlier steps are done. */
    current: number;
    className?: string;
};

/**
 * Progress through a multi-step flow: done steps get a lime disc with a check, the
 * current step an ink disc (`aria-current="step"`), later steps their number.
 */
export function Stepper({
    'aria-label': ariaLabel,
    steps,
    current,
    className,
}: StepperProps) {
    return (
        <ol className={cn('cf-stepper', className)} aria-label={ariaLabel}>
            {steps.map((label, i) => {
                const done = i < current;

                return (
                    <Fragment key={i}>
                        {i > 0 && (
                            <li
                                className={cn(
                                    'cf-step-line',
                                    i <= current && 'is-done',
                                )}
                                aria-hidden="true"
                            />
                        )}
                        <li
                            className={cn('cf-step', done && 'is-done')}
                            aria-current={i === current ? 'step' : undefined}
                        >
                            <span className="cf-step-n">
                                {done ? (
                                    <Icon
                                        icon={Check}
                                        size={14}
                                        strokeWidth={2.5}
                                    />
                                ) : (
                                    i + 1
                                )}
                            </span>
                            {label}
                            {done && <span className="cf-sr"> (done)</span>}
                        </li>
                    </Fragment>
                );
            })}
        </ol>
    );
}
