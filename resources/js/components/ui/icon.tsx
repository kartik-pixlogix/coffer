import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type IconProps = {
    icon: LucideIcon;
    size?: number;
    /** Stroke in px; the `ic` class defaults to 1.5, so other values go inline like the design. */
    strokeWidth?: number;
    className?: string;
};

/**
 * A lucide icon rendered like the design system's `<svg class="ic">`: line icon,
 * round caps, `flex:none`, decorative (`aria-hidden`). Label the control, not the icon.
 */
export function Icon({
    icon: Glyph,
    size = 18,
    strokeWidth = 1.5,
    className,
}: IconProps) {
    return (
        <Glyph
            width={size}
            height={size}
            className={cn('ic', className)}
            style={strokeWidth === 1.5 ? undefined : { strokeWidth }}
            aria-hidden="true"
            focusable="false"
        />
    );
}

/** `cf-spinner`: the loading ring used inside buttons, inputs and rows. */
export function Spinner({ className }: { className?: string }) {
    return <span className={cn('cf-spinner', className)} aria-hidden="true" />;
}
