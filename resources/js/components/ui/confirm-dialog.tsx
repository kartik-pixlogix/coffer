import * as DialogPrimitive from '@radix-ui/react-dialog';
import { TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { CSSProperties, ReactNode, Ref } from 'react';
import { Button, buttonClass } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { ModalFrame, SCRIM_CLASS } from '@/components/ui/modal';
import { cn } from '@/lib/utils';

const TITLE_STYLE: CSSProperties = { fontSize: 20, lineHeight: '24px' };

const LIVE_DIALOG = 'cf-modal--live focus-visible:shadow-none';

type ConfirmLookProps = {
    /** A question naming the thing: "Delete this expense?" */
    title: ReactNode;
    /** What happens, with the name and amount. */
    description?: ReactNode;
    /** Icon in a negative-soft disc before the title (e.g. Trash2). */
    icon?: LucideIcon;
    /** The outcome: "Delete expense", "Close FY". */
    confirmLabel: string;
    /** The safe outcome: "Keep it", "Cancel". */
    cancelLabel?: string;
    /** Type-to-confirm: the exact phrase, shown in `cf-confirm-code`. */
    confirmPhrase?: string;
    /** The destructive action is running. */
    loading?: boolean;
    /** Extra content under the description (a preview, warnings). */
    children?: ReactNode;
    className?: string;
    style?: CSSProperties;
};

type ConfirmBodyProps = Omit<
    ConfirmLookProps,
    'title' | 'className' | 'style'
> & {
    titleNode: ReactNode;
    descriptionId: string;
    typed: string;
    onTypedChange: (value: string) => void;
    onCancel?: () => void;
    onConfirm?: () => void;
    cancelRef?: Ref<HTMLButtonElement>;
    inputRef?: Ref<HTMLInputElement>;
    /** Gallery only: draw the input focused. */
    inputClassName?: string;
};

function ConfirmBody({
    titleNode,
    description,
    descriptionId,
    icon,
    confirmLabel,
    cancelLabel = 'Cancel',
    confirmPhrase,
    loading = false,
    typed,
    onTypedChange,
    onCancel,
    onConfirm,
    cancelRef,
    inputRef,
    inputClassName,
    children,
}: ConfirmBodyProps) {
    const inputId = useId();
    const matches = confirmPhrase === undefined || typed === confirmPhrase;

    return (
        <>
            {icon ? (
                <div className="cf-row" style={{ gap: 12 }}>
                    <span
                        className="cf-alert-ic"
                        style={{
                            width: 40,
                            height: 40,
                            background: 'var(--negative-soft)',
                            color: 'var(--on-negative-soft)',
                        }}
                    >
                        <Icon icon={icon} size={18} />
                    </span>
                    {titleNode}
                </div>
            ) : (
                titleNode
            )}
            {description && (
                <p id={descriptionId} className="t-body t-muted m-0">
                    {description}
                </p>
            )}
            {children}
            {confirmPhrase !== undefined && (
                <div className="cf-field">
                    <label className="cf-label" htmlFor={inputId}>
                        Type{' '}
                        <span className="cf-confirm-code">{confirmPhrase}</span>{' '}
                        to confirm
                    </label>
                    <input
                        ref={inputRef}
                        id={inputId}
                        className={cn('cf-input cf-input--sm', inputClassName)}
                        type="text"
                        value={typed}
                        onChange={(event) => onTypedChange(event.target.value)}
                        autoComplete="off"
                        autoCapitalize="off"
                        spellCheck={false}
                        style={{ fontFamily: 'var(--font-mono)' }}
                    />
                </div>
            )}
            <div className="cf-actions" style={{ justifyContent: 'flex-end' }}>
                <button
                    ref={cancelRef}
                    type="button"
                    className={buttonClass({ variant: 'secondary' })}
                    onClick={onCancel}
                    disabled={loading}
                >
                    {cancelLabel}
                </button>
                <Button
                    variant="danger"
                    type="submit"
                    disabled={!matches || loading}
                    loading={loading}
                    onClick={onConfirm}
                >
                    {confirmLabel}
                </Button>
            </div>
        </>
    );
}

export type ConfirmPanelProps = ConfirmLookProps & {
    /** Prefill the type-to-confirm input (gallery states). */
    typed?: string;
    inputClassName?: string;
};

/** The static confirmation card (`cf-modal--static`), for the gallery and docs. */
export function ConfirmPanel({
    title,
    className,
    style,
    typed: initialTyped = '',
    ...rest
}: ConfirmPanelProps) {
    const titleId = useId();
    const descriptionId = useId();
    const [typed, setTyped] = useState(initialTyped);

    return (
        <div
            className={cn('cf-modal cf-modal--static cf-modal--sm', className)}
            role="alertdialog"
            aria-modal="true"
            aria-labelledby={titleId}
            aria-describedby={rest.description ? descriptionId : undefined}
            style={style}
        >
            <ConfirmBody
                {...rest}
                titleNode={
                    <h2
                        id={titleId}
                        className="t-heading m-0"
                        style={TITLE_STYLE}
                    >
                        {title}
                    </h2>
                }
                descriptionId={descriptionId}
                typed={typed}
                onTypedChange={setTyped}
            />
        </div>
    );
}

export type ConfirmDialogProps = Omit<ConfirmLookProps, 'style'> & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Runs the destructive action; close the dialog when it finishes. */
    onConfirm: () => void;
};

/**
 * Confirmation before anything destructive (`role="alertdialog"`, `cf-modal--sm`).
 * The safe button takes initial focus (the phrase input does, when there is one);
 * Esc and the safe button cancel; the scrim never confirms or closes it.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    onConfirm,
    title,
    description,
    confirmPhrase,
    loading = false,
    className,
    // One look for every confirmation (X31, D131): the red disc always shows.
    icon = TriangleAlert,
    ...rest
}: ConfirmDialogProps) {
    const descriptionId = useId();
    const cancelRef = useRef<HTMLButtonElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const [typed, setTyped] = useState('');
    const matches = confirmPhrase === undefined || typed === confirmPhrase;

    const change = (next: boolean) => {
        if (!next && loading) {
            return;
        }

        if (!next) {
            setTyped('');
        }

        onOpenChange(next);
    };

    return (
        <DialogPrimitive.Root open={open} onOpenChange={change}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className={SCRIM_CLASS} />
                <ModalFrame phone="bottom">
                    <DialogPrimitive.Content
                        role="alertdialog"
                        className={cn(
                            'cf-modal cf-modal--sm',
                            LIVE_DIALOG,
                            className,
                        )}
                        aria-describedby={
                            description ? descriptionId : undefined
                        }
                        onOpenAutoFocus={(event) => {
                            event.preventDefault();
                            (inputRef.current ?? cancelRef.current)?.focus();
                        }}
                        onPointerDownOutside={(event) => event.preventDefault()}
                        onInteractOutside={(event) => event.preventDefault()}
                        asChild
                    >
                        <form
                            noValidate
                            onSubmit={(event) => {
                                event.preventDefault();

                                if (matches && !loading) {
                                    onConfirm();
                                }
                            }}
                        >
                            <ConfirmBody
                                {...rest}
                                icon={icon}
                                description={description}
                                confirmPhrase={confirmPhrase}
                                loading={loading}
                                titleNode={
                                    <DialogPrimitive.Title
                                        className="t-heading m-0"
                                        style={TITLE_STYLE}
                                    >
                                        {title}
                                    </DialogPrimitive.Title>
                                }
                                descriptionId={descriptionId}
                                typed={typed}
                                onTypedChange={setTyped}
                                onCancel={() => change(false)}
                                cancelRef={cancelRef}
                                inputRef={inputRef}
                            />
                        </form>
                    </DialogPrimitive.Content>
                </ModalFrame>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
