import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useRef } from 'react';
import type { CSSProperties, FormEvent, ReactElement, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { cn } from '@/lib/utils';

export type ModalSize = 'sm' | 'default' | 'lg';

const SIZE_CLASS: Record<ModalSize, string | undefined> = {
    sm: 'cf-modal--sm',
    default: undefined,
    lg: 'cf-modal--lg',
};

/*
 * Portalled overlays sit on the viewport, so the absolute cf-scrim / cf-modal are
 * pinned with `fixed`. At phone width the modal becomes a full-height sheet.
 */
export const SCRIM_CLASS =
    'cf-scrim fixed animate-[cfFade_var(--duration-panel)_var(--ease-out)]';

const LIVE_MODAL =
    'fixed focus-visible:shadow-none max-h-[calc(100dvh-136px)] overflow-y-auto max-sm:inset-0 max-sm:max-h-none max-sm:w-full max-sm:max-w-full max-sm:transform-none max-sm:animate-[cfSheet_var(--duration-panel)_var(--ease-out)_both] max-sm:rounded-none max-sm:border-0';

/** Fields that should take focus when a modal opens, in order of preference. */
const FIRST_FIELD =
    '[data-autofocus], input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)';

type ModalLookProps = {
    title: ReactNode;
    /** Caption under the title: "Saarthi Portal JV · capital account". */
    subtitle?: ReactNode;
    /** Icon in the lime disc before the title. */
    icon?: LucideIcon;
    size?: ModalSize;
    /** The form. Fields sit directly in the modal's 16px column. */
    children: ReactNode;
    /** Footer actions: Cancel, then the primary pill. */
    footer?: ReactNode;
    closeLabel?: string;
    className?: string;
    style?: CSSProperties;
};

function ModalHeadLayout({
    icon,
    title,
    subtitle,
    close,
}: {
    icon?: LucideIcon;
    title: ReactNode;
    subtitle?: ReactNode;
    close: ReactNode;
}) {
    return (
        <div className="cf-modal-head">
            <div className="cf-modal-title">
                {icon && (
                    <span className="cf-disc" style={{ width: 40, height: 40 }}>
                        <Icon icon={icon} size={18} />
                    </span>
                )}
                <div>
                    {title}
                    {subtitle}
                </div>
            </div>
            {close}
        </div>
    );
}

export type ModalPanelProps = ModalLookProps & { onClose?: () => void };

/** The static open modal (no scrim, no portal), for the gallery and docs. */
export function ModalPanel({
    title,
    subtitle,
    icon,
    size = 'default',
    children,
    footer,
    closeLabel = 'Close',
    onClose,
    className,
    style,
}: ModalPanelProps) {
    const titleId = useId();

    return (
        <div
            className={cn('cf-modal', SIZE_CLASS[size], className)}
            role="dialog"
            aria-modal="true"
            aria-labelledby={titleId}
            style={style}
        >
            <ModalHeadLayout
                icon={icon}
                title={
                    <h2 id={titleId} className="t-heading m-0">
                        {title}
                    </h2>
                }
                subtitle={
                    subtitle && (
                        <span className="t-caption t-muted">{subtitle}</span>
                    )
                }
                close={
                    <IconButton
                        icon={X}
                        aria-label={closeLabel}
                        onClick={onClose}
                    />
                }
            />
            {children}
            {footer && <div className="cf-modal-foot">{footer}</div>}
        </div>
    );
}

export type ModalProps = ModalLookProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Optional opener; without it, focus returns to whatever had it before. */
    trigger?: ReactElement;
    /**
     * The form has unsaved input: a click on the scrim no longer closes it
     * (Esc and the close button still cancel).
     */
    dirty?: boolean;
    /** Wrap body + footer in a <form> with this submit handler. */
    onSubmit?: (event: FormEvent<HTMLFormElement>) => void;
};

/**
 * A short, focused task over the scrim (add expense, record contribution). Focus
 * moves to the first field and is trapped; Esc and the close button cancel.
 * Widths: 600 default, `sm` 440, `lg` 760; a full-height sheet at phone width.
 */
export function Modal({
    open,
    onOpenChange,
    trigger,
    dirty = false,
    onSubmit,
    title,
    subtitle,
    icon,
    size = 'default',
    children,
    footer,
    closeLabel = 'Close',
    className,
    style,
}: ModalProps) {
    const contentRef = useRef<HTMLDivElement>(null);

    const body = (
        <>
            {children}
            {footer && <div className="cf-modal-foot">{footer}</div>}
        </>
    );

    return (
        <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
            {trigger && (
                <DialogPrimitive.Trigger asChild>
                    {trigger}
                </DialogPrimitive.Trigger>
            )}
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className={SCRIM_CLASS} />
                <DialogPrimitive.Content
                    ref={contentRef}
                    className={cn(
                        'cf-modal',
                        SIZE_CLASS[size],
                        LIVE_MODAL,
                        className,
                    )}
                    style={style}
                    {...(subtitle ? {} : { 'aria-describedby': undefined })}
                    onOpenAutoFocus={(event) => {
                        const field =
                            contentRef.current?.querySelector<HTMLElement>(
                                FIRST_FIELD,
                            );

                        if (field) {
                            event.preventDefault();
                            field.focus();
                        }
                    }}
                    onPointerDownOutside={(event) => {
                        if (dirty) {
                            event.preventDefault();
                        }
                    }}
                    onInteractOutside={(event) => {
                        if (dirty) {
                            event.preventDefault();
                        }
                    }}
                >
                    <ModalHeadLayout
                        icon={icon}
                        title={
                            <DialogPrimitive.Title className="t-heading m-0">
                                {title}
                            </DialogPrimitive.Title>
                        }
                        subtitle={
                            subtitle && (
                                <DialogPrimitive.Description asChild>
                                    <span className="t-caption t-muted">
                                        {subtitle}
                                    </span>
                                </DialogPrimitive.Description>
                            )
                        }
                        close={
                            <DialogPrimitive.Close asChild>
                                <IconButton icon={X} aria-label={closeLabel} />
                            </DialogPrimitive.Close>
                        }
                    />
                    {onSubmit ? (
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={onSubmit}
                            noValidate
                        >
                            {body}
                        </form>
                    ) : (
                        body
                    )}
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
