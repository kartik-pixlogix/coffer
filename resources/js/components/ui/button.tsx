import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Icon, Spinner } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type ButtonVariant =
    | 'primary'
    | 'secondary'
    | 'ghost'
    | 'danger'
    | 'muted'
    | 'lime'
    | 'glass'
    | 'onDark';

export type ButtonSize = 'lg' | 'md' | 'sm';

type ButtonLookProps = {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Icon inside the lime arrow disc on the right (`cf-btn--disc`), used by the primary pill. */
    disc?: LucideIcon;
    /** Icon before the label. */
    icon?: LucideIcon;
    loading?: boolean;
    /** Error outline next to an error message (`.is-error` + `aria-invalid`). */
    invalid?: boolean;
    /** `aria-pressed` selection (lime), for toggle buttons. */
    pressed?: boolean;
    className?: string;
    children?: ReactNode;
};

const DISC_ICON: Record<ButtonSize, { size: number; stroke: number }> = {
    lg: { size: 18, stroke: 1.75 },
    md: { size: 16, stroke: 1.75 },
    sm: { size: 14, stroke: 2 },
};

const LEAD_ICON: Record<ButtonSize, number> = { lg: 18, md: 16, sm: 14 };

/** Class list for a `cf-btn`, for elements that can't use <Button>. */
export function buttonClass({
    variant = 'secondary',
    size = 'md',
    disc,
    loading,
    invalid,
    className,
}: Pick<
    ButtonLookProps,
    'variant' | 'size' | 'disc' | 'loading' | 'invalid' | 'className'
>): string {
    return cn(
        'cf-btn',
        `cf-btn--${variant}`,
        size !== 'md' && `cf-btn--${size}`,
        disc && 'cf-btn--disc',
        loading && 'is-loading',
        invalid && 'is-error',
        className,
    );
}

function ButtonContent({
    size = 'md',
    disc,
    icon,
    loading,
    children,
}: Pick<ButtonLookProps, 'size' | 'disc' | 'icon' | 'loading' | 'children'>) {
    if (disc) {
        return (
            <>
                {icon && (
                    <Icon
                        icon={icon}
                        size={LEAD_ICON[size]}
                        strokeWidth={1.75}
                    />
                )}
                {children}
                <span className="cf-disc">
                    {loading ? (
                        <Spinner />
                    ) : (
                        <Icon
                            icon={disc}
                            size={DISC_ICON[size].size}
                            strokeWidth={DISC_ICON[size].stroke}
                        />
                    )}
                </span>
            </>
        );
    }

    return (
        <>
            {loading ? (
                <Spinner />
            ) : (
                icon && (
                    <Icon
                        icon={icon}
                        size={LEAD_ICON[size]}
                        strokeWidth={1.75}
                    />
                )
            )}
            {children}
        </>
    );
}

export type ButtonProps = ButtonLookProps &
    Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children' | 'className'>;

/**
 * Design-system Button. One graphite `primary` pill with a `disc` per view; `secondary`
 * next, `ghost` low emphasis, `danger` for destructive actions only.
 */
export function Button({
    variant = 'secondary',
    size = 'md',
    disc,
    icon,
    loading = false,
    invalid = false,
    pressed,
    className,
    children,
    type = 'button',
    disabled,
    ...rest
}: ButtonProps) {
    return (
        <button
            type={type}
            className={buttonClass({
                variant,
                size,
                disc,
                loading,
                invalid,
                className,
            })}
            disabled={disabled}
            aria-busy={loading || undefined}
            aria-invalid={invalid || undefined}
            aria-pressed={pressed}
            {...rest}
        >
            <ButtonContent
                size={size}
                disc={disc}
                icon={icon}
                loading={loading}
            >
                {children}
            </ButtonContent>
        </button>
    );
}

export type ButtonLinkProps = Omit<
    ButtonLookProps,
    'loading' | 'invalid' | 'pressed'
> &
    Omit<InertiaLinkProps, 'children' | 'className' | 'size'>;

/** A link that looks like a Button (Inertia navigation). */
export function ButtonLink({
    variant = 'secondary',
    size = 'md',
    disc,
    icon,
    className,
    children,
    ...rest
}: ButtonLinkProps) {
    return (
        <Link
            className={buttonClass({ variant, size, disc, className })}
            {...rest}
        >
            <ButtonContent size={size} disc={disc} icon={icon}>
                {children}
            </ButtonContent>
        </Link>
    );
}

/** Inline text action (`cf-link`), e.g. "Forgot password?". */
export function TextButton({
    className,
    type = 'button',
    ...rest
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button type={type} className={cn('cf-link', className)} {...rest} />
    );
}
