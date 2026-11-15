import { useId } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type SegmentedOption<T extends string> = {
    value: T;
    label: ReactNode;
    disabled?: boolean;
    /** Doc-only forced state (`is-hover`, `is-focus`). */
    className?: string;
};

type Labelling =
    | {
          /** Visible label; renders the control inside a `cf-field` with a `cf-label`. */
          label: ReactNode;
          'aria-labelledby'?: never;
          'aria-label'?: never;
      }
    | { label?: never; 'aria-labelledby': string; 'aria-label'?: never }
    | { label?: never; 'aria-labelledby'?: never; 'aria-label': string };

export type SegmentedControlProps<T extends string> = Labelling & {
    options: SegmentedOption<T>[];
    value: T;
    onValueChange: (value: T) => void;
    /** `sm` = 32 px options (`cf-seg--sm`). */
    size?: 'md' | 'sm';
    disabled?: boolean;
    className?: string;
};

/**
 * Choose one of two to four options that change the form below (Full / Half / Custom /
 * Direct company). The chosen option is solid lime. Keep what the person already typed
 * when the option changes.
 */
export function SegmentedControl<T extends string>({
    options,
    value,
    onValueChange,
    size = 'md',
    disabled = false,
    className,
    label,
    'aria-labelledby': labelledBy,
    'aria-label': ariaLabel,
}: SegmentedControlProps<T>) {
    const labelId = useId();
    const hasLabel = label !== undefined;

    const control = (
        <div
            className={cn('cf-seg', size === 'sm' && 'cf-seg--sm', className)}
            role="group"
            aria-labelledby={hasLabel ? labelId : labelledBy}
            aria-label={ariaLabel}
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    className={cn('cf-seg-btn', option.className)}
                    aria-pressed={option.value === value}
                    disabled={disabled || option.disabled}
                    onClick={() => onValueChange(option.value)}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );

    if (!hasLabel) {
        return control;
    }

    return (
        <div className="cf-field">
            <span className="cf-label" id={labelId}>
                {label}
            </span>
            {control}
        </div>
    );
}
