import * as PopoverPrimitive from '@radix-ui/react-popover';
import { X } from 'lucide-react';
import { useId } from 'react';
import type { CSSProperties, ReactElement, ReactNode } from 'react';
import { IconButton } from '@/components/ui/icon-button';
import type { IconButtonProps } from '@/components/ui/icon-button';
import { cn } from '@/lib/utils';

type PopoverLookProps = {
    /** Shown in `t-title`; also names the dialog. */
    title: ReactNode;
    /** One task, one primary action. Anything longer belongs in a modal. */
    children: ReactNode;
    closeLabel?: string;
    className?: string;
    style?: CSSProperties;
};

function PopoverHead({
    titleId,
    title,
    close,
}: {
    titleId: string;
    title: ReactNode;
    close: ReactNode;
}) {
    return (
        <div className="cf-kv">
            <span id={titleId} className="t-title">
                {title}
            </span>
            {close}
        </div>
    );
}

function CloseButton({
    label,
    ...rest
}: { label: string } & Omit<IconButtonProps, 'icon' | 'aria-label'>) {
    return (
        <IconButton
            icon={X}
            size="sm"
            variant="bare"
            iconSize={14}
            aria-label={label}
            {...rest}
        />
    );
}

export type PopoverPanelProps = PopoverLookProps & {
    onClose?: () => void;
};

/** The static, open popover (`cf-pop cf-popover`), for the gallery and docs. */
export function PopoverPanel({
    title,
    children,
    closeLabel = 'Close',
    onClose,
    className,
    style,
}: PopoverPanelProps) {
    const titleId = useId();

    return (
        <div
            className={cn('cf-pop cf-popover', className)}
            role="dialog"
            aria-labelledby={titleId}
            style={style}
        >
            <PopoverHead
                titleId={titleId}
                title={title}
                close={<CloseButton label={closeLabel} onClick={onClose} />}
            />
            {children}
        </div>
    );
}

export type PopoverProps = PopoverLookProps & {
    /** The button that opens it (receives `aria-expanded` and `aria-controls`). */
    trigger: ReactElement;
    open?: boolean;
    defaultOpen?: boolean;
    onOpenChange?: (open: boolean) => void;
    side?: 'top' | 'right' | 'bottom' | 'left';
    align?: 'start' | 'center' | 'end';
    sideOffset?: number;
};

/**
 * A small floating panel tied to one field or button (password generator, column
 * picker). Closes on Esc, an outside click or its close button; focus returns to
 * the trigger.
 */
export function Popover({
    trigger,
    title,
    children,
    closeLabel = 'Close',
    open,
    defaultOpen,
    onOpenChange,
    side = 'bottom',
    align = 'start',
    sideOffset = 8,
    className,
    style,
}: PopoverProps) {
    const titleId = useId();

    return (
        <PopoverPrimitive.Root
            open={open}
            defaultOpen={defaultOpen}
            onOpenChange={onOpenChange}
        >
            <PopoverPrimitive.Trigger asChild>
                {trigger}
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Content
                    side={side}
                    align={align}
                    sideOffset={sideOffset}
                    collisionPadding={16}
                    aria-labelledby={titleId}
                    className={cn(
                        'cf-pop cf-popover z-50 w-[360px] max-w-[calc(100vw-32px)] animate-[cfFade_var(--duration-panel)_var(--ease-out)] focus-visible:shadow-pop',
                        className,
                    )}
                    style={style}
                >
                    <PopoverHead
                        titleId={titleId}
                        title={title}
                        close={
                            <PopoverPrimitive.Close asChild>
                                <CloseButton label={closeLabel} />
                            </PopoverPrimitive.Close>
                        }
                    />
                    {children}
                </PopoverPrimitive.Content>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}
