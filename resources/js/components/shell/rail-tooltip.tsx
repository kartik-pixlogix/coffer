import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import type { ReactNode } from 'react';

/**
 * Design-system tooltip (cf-tooltip) on Radix for focus and hover timing.
 * Shows above the trigger after a short delay; never holds actions.
 */
export function RailTooltip({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <TooltipPrimitive.Root delayDuration={300}>
            <TooltipPrimitive.Trigger asChild>
                {children}
            </TooltipPrimitive.Trigger>
            <TooltipPrimitive.Portal>
                <TooltipPrimitive.Content
                    side="top"
                    sideOffset={10}
                    className="cf-tooltip z-50"
                >
                    {label}
                </TooltipPrimitive.Content>
            </TooltipPrimitive.Portal>
        </TooltipPrimitive.Root>
    );
}
