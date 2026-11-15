import type { LucideIcon } from 'lucide-react';
import type { ButtonHTMLAttributes, Ref } from 'react';
import { Icon, Spinner } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type IconButtonSize = 'lg' | 'md' | 'sm';
export type IconButtonVariant =
    | 'default'
    | 'pos'
    | 'neg'
    | 'graphite'
    | 'glass'
    | 'bare';

const ICON_SIZE: Record<IconButtonSize, number> = { lg: 20, md: 18, sm: 15 };

export type IconButtonProps = {
    icon: LucideIcon;
    /** Required: icon-only controls must be named. */
    'aria-label': string;
    size?: IconButtonSize;
    variant?: IconButtonVariant;
    /** Override the glyph size (e.g. 16 for copy buttons, 22 for the bell). */
    iconSize?: number;
    strokeWidth?: number;
    loading?: boolean;
    invalid?: boolean;
    /** `aria-pressed`: lime when on (view toggles). */
    pressed?: boolean;
    ref?: Ref<HTMLButtonElement>;
} & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'aria-label' | 'children'>;

/** Round icon button (`cf-iconbtn`): 54 top bar, 40 default, 32 table rows. */
export function IconButton({
    icon,
    size = 'md',
    variant = 'default',
    iconSize,
    strokeWidth,
    loading = false,
    invalid = false,
    pressed,
    className,
    type = 'button',
    ref,
    ...rest
}: IconButtonProps) {
    const stroke =
        strokeWidth ??
        (size === 'sm' && (variant === 'pos' || variant === 'neg') ? 2 : 1.5);

    return (
        <button
            ref={ref}
            type={type}
            className={cn(
                'cf-iconbtn',
                size !== 'md' && `cf-iconbtn--${size}`,
                variant !== 'default' && `cf-iconbtn--${variant}`,
                invalid && 'is-error',
                className,
            )}
            aria-busy={loading || undefined}
            aria-pressed={pressed}
            {...rest}
        >
            {loading ? (
                <Spinner />
            ) : (
                <Icon
                    icon={icon}
                    size={iconSize ?? ICON_SIZE[size]}
                    strokeWidth={stroke}
                />
            )}
        </button>
    );
}
