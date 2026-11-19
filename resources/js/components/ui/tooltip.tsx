import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import type { ReactElement, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Show delay for every tooltip in the app (design system: "after a short delay"). */
export const TOOLTIP_DELAY = 300;

/** Mount once near the root; every <Tooltip> needs it. */
export function TooltipProvider({
    children,
    delayDuration = TOOLTIP_DELAY,
}: {
    children: ReactNode;
    delayDuration?: number;
}) {
    return (
        <TooltipPrimitive.Provider delayDuration={delayDuration}>
            {children}
        </TooltipPrimitive.Provider>
    );
}

export type TooltipPanelProps = {
    id?: string;
    children: ReactNode;
    className?: string;
};

/**
 * The static tooltip bubble (`cf-tooltip`): graphite, 12px, arrow underneath.
 * Use <Tooltip> in the app; this is the shape on its own (gallery, docs).
 */
export function TooltipPanel({ id, children, className }: TooltipPanelProps) {
    return (
        <span className={cn('cf-tooltip', className)} role="tooltip" id={id}>
            {children}
        </span>
    );
}

export type TooltipProps = {
    /** One line. Wrap money in `t-money-sm`. Never put actions in a tooltip. */
    label: ReactNode;
    /** The trigger: one focusable element that accepts a ref (link, button, `tabIndex=0` span). */
    children: ReactElement;
    delayDuration?: number;
    /** Controlled open state (rarely needed). */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    sideOffset?: number;
    /** Where it opens (default above); the nav rail's open to the right (G6). */
    side?: 'top' | 'right' | 'bottom' | 'left';
    /** Hide the tooltip without unwrapping the trigger. */
    disabled?: boolean;
};

/**
 * Names an icon-only control or explains a figure in one line. Opens above the
 * trigger on hover and keyboard focus after {@link TOOLTIP_DELAY}ms; the trigger is
 * linked with `aria-describedby`. Icon-only triggers still need their own `aria-label`.
 */
export function Tooltip({
    label,
    children,
    delayDuration,
    open,
    onOpenChange,
    sideOffset = 10,
    side = 'top',
    disabled = false,
}: TooltipProps) {
    if (disabled) {
        return children;
    }

    return (
        <TooltipPrimitive.Root
            delayDuration={delayDuration}
            open={open}
            onOpenChange={onOpenChange}
        >
            <TooltipPrimitive.Trigger asChild>
                {children}
            </TooltipPrimitive.Trigger>
            <TooltipPrimitive.Portal>
                <TooltipPrimitive.Content
                    side={side}
                    align="center"
                    sideOffset={sideOffset}
                    collisionPadding={8}
                    className="cf-tooltip z-[70] animate-[cfFade_var(--duration-fast)_var(--ease-out)] data-[side=bottom]:after:top-[-4px] data-[side=bottom]:after:bottom-auto data-[side=left]:after:top-1/2 data-[side=left]:after:right-[-4px] data-[side=left]:after:bottom-auto data-[side=left]:after:left-auto data-[side=left]:after:-mt-1 data-[side=left]:after:ml-0 data-[side=right]:after:top-1/2 data-[side=right]:after:bottom-auto data-[side=right]:after:left-[-4px] data-[side=right]:after:-mt-1 data-[side=right]:after:ml-0"
                >
                    {label}
                </TooltipPrimitive.Content>
            </TooltipPrimitive.Portal>
        </TooltipPrimitive.Root>
    );
}
