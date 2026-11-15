import type { LucideIcon } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Icon, Spinner } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type ButtonGroupProps = {
    /** Names the group for assistive tech ("Chart range", "View"). */
    'aria-label': string;
    children: ReactNode;
    className?: string;
};

/**
 * Joined buttons for switching between two to five views of the same data
 * (Month / Quarter / FY, list / grid). Exactly one button is pressed; for choosing a
 * form value use SegmentedControl (lime selection) instead.
 */
export function ButtonGroup({
    'aria-label': ariaLabel,
    children,
    className,
}: ButtonGroupProps) {
    return (
        <div
            className={cn('cf-btn-group', className)}
            role="group"
            aria-label={ariaLabel}
        >
            {children}
        </div>
    );
}

export type ButtonGroupButtonProps = {
    /** Always rendered as `aria-pressed="true|false"`. */
    pressed: boolean;
    /** Icon-only button (16 px); pair it with `aria-label`. */
    icon?: LucideIcon;
    loading?: boolean;
    children?: ReactNode;
} & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'aria-pressed'>;

/** One `cf-btn cf-btn--sm` inside a ButtonGroup: the pressed one sits on `surface`. */
export function ButtonGroupButton({
    pressed,
    icon,
    loading = false,
    className,
    children,
    type = 'button',
    ...rest
}: ButtonGroupButtonProps) {
    return (
        <button
            type={type}
            className={cn(
                'cf-btn cf-btn--sm',
                loading && 'is-loading',
                className,
            )}
            aria-pressed={pressed}
            aria-busy={loading || undefined}
            {...rest}
        >
            {loading ? <Spinner /> : icon && <Icon icon={icon} size={16} />}
            {children}
        </button>
    );
}

export type ButtonGroupOption<T extends string> = {
    value: T;
    /** Visible text; for icon-only options give `icon` and `label` becomes the aria-label. */
    label: string;
    icon?: LucideIcon;
    disabled?: boolean;
    loading?: boolean;
    /** Doc-only forced state (`is-hover`, `is-focus`). */
    className?: string;
};

export type ButtonGroupSelectProps<T extends string> = {
    'aria-label': string;
    options: ButtonGroupOption<T>[];
    value: T;
    onValueChange: (value: T) => void;
    className?: string;
};

/** A ButtonGroup driven by a value: one option pressed at a time. */
export function ButtonGroupSelect<T extends string>({
    'aria-label': ariaLabel,
    options,
    value,
    onValueChange,
    className,
}: ButtonGroupSelectProps<T>) {
    return (
        <ButtonGroup aria-label={ariaLabel} className={className}>
            {options.map((option) => (
                <ButtonGroupButton
                    key={option.value}
                    pressed={option.value === value}
                    icon={option.icon}
                    loading={option.loading}
                    disabled={option.disabled}
                    className={option.className}
                    aria-label={option.icon ? option.label : undefined}
                    onClick={() => onValueChange(option.value)}
                >
                    {option.icon ? undefined : option.label}
                </ButtonGroupButton>
            ))}
        </ButtonGroup>
    );
}
