import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { IconButton } from '@/components/ui/icon-button';
import { SCRIM_CLASS } from '@/components/ui/modal';

type SheetProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    children: ReactNode;
    /** Full-width actions at the bottom (`cf-sheet-foot`). */
    footer?: ReactNode;
    closeLabel?: string;
    /** Opened from the phone menu (`app-menu`, z-index 50): sits above it instead of behind. */
    raised?: boolean;
};

/** One step above the phone menu. */
const RAISED_Z = 60;

/**
 * Phone bottom sheet (`cf-sheet`, MobileLogHours.dc.html): grab handle, title with
 * Close, scrolling body and a footer. Radix Dialog traps focus, Esc closes and focus
 * returns to the opener; the scrim closes it too.
 */
export function Sheet({
    open,
    onOpenChange,
    title,
    children,
    footer,
    closeLabel = 'Close',
    raised = false,
}: SheetProps) {
    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay
                    className={SCRIM_CLASS}
                    style={raised ? { zIndex: RAISED_Z } : undefined}
                />
                <DialogPrimitive.Content
                    className="cf-sheet"
                    // The bundle positions the sheet inside a phone frame; on a real phone it sits on the viewport.
                    style={{
                        position: 'fixed',
                        maxHeight: 'calc(100dvh - 44px)',
                        ...(raised ? { zIndex: RAISED_Z + 1 } : {}),
                    }}
                    aria-describedby={undefined}
                >
                    <span className="cf-sheet-grab" aria-hidden="true" />
                    <div className="cf-sheet-head">
                        <DialogPrimitive.Title className="t-heading m-0">
                            {title}
                        </DialogPrimitive.Title>
                        <DialogPrimitive.Close asChild>
                            <IconButton icon={X} aria-label={closeLabel} />
                        </DialogPrimitive.Close>
                    </div>
                    <div className="cf-sheet-body">{children}</div>
                    {footer && <div className="cf-sheet-foot">{footer}</div>}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
