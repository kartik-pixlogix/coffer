import { Check, Info, TriangleAlert, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type ToastKind = 'success' | 'error' | 'info';

export type ToastAction = {
    /** A verb: "Undo", "Retry". Running it dismisses the toast. */
    label: string;
    onClick: () => void;
};

export type ToastOptions = {
    action?: ToastAction;
    /** Override the icon (e.g. Trash2 for "Expense deleted"). */
    icon?: LucideIcon;
    /**
     * Milliseconds before it leaves. Defaults: 2.6s, 8s when it offers an action,
     * errors never (they stay until dismissed).
     */
    duration?: number;
};

export type ToastRecord = {
    id: number;
    kind: ToastKind;
    message: string;
    action?: ToastAction;
    icon?: LucideIcon;
    duration: number | null;
};

/** README: toasts leave after about 2.6 seconds. */
export const TOAST_DURATION = 2600;
/** Long enough to reach an Undo (StateToasts.dc.html). */
export const TOAST_ACTION_DURATION = 8000;
/** Up to three at once, newest on top. */
export const TOAST_LIMIT = 3;

const KIND_ICON: Record<ToastKind, LucideIcon> = {
    success: Check,
    error: TriangleAlert,
    info: Info,
};

/* --------------------------------------------------------------- the store */

let toasts: ToastRecord[] = [];
let seq = 0;
const listeners = new Set<() => void>();

const emit = () => listeners.forEach((listener) => listener());

function push(kind: ToastKind, message: string, options: ToastOptions = {}) {
    const id = ++seq;
    const duration =
        kind === 'error'
            ? (options.duration ?? null)
            : (options.duration ??
              (options.action ? TOAST_ACTION_DURATION : TOAST_DURATION));

    toasts = [
        {
            id,
            kind,
            message,
            action: options.action,
            icon: options.icon,
            duration,
        },
        ...toasts,
    ].slice(0, TOAST_LIMIT);
    emit();

    return id;
}

function dismiss(id?: number) {
    toasts = id === undefined ? [] : toasts.filter((t) => t.id !== id);
    emit();
}

export type ToastApi = {
    success: (message: string, options?: ToastOptions) => number;
    error: (message: string, options?: ToastOptions) => number;
    info: (message: string, options?: ToastOptions) => number;
    /** Dismiss one toast, or all of them. */
    dismiss: (id?: number) => void;
};

/**
 * Fire a toast from anywhere (also outside React). Inside components prefer
 * `useToast()`. Rendered by the one <ToastProvider> at the app root.
 */
export const toast: ToastApi = {
    success: (message, options) => push('success', message, options),
    error: (message, options) => push('error', message, options),
    info: (message, options) => push('info', message, options),
    dismiss,
};

export function useToast(): ToastApi {
    return toast;
}

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const EMPTY: ToastRecord[] = [];

/* -------------------------------------------------------------- the views */

export type ToastViewProps = {
    kind?: ToastKind;
    children: ReactNode;
    icon?: LucideIcon;
    action?: ToastAction;
    /** Shows the dismiss button (live toasts). */
    onDismiss?: () => void;
    /** Pin it to the viewport's top right on its own (`cf-toast--fixed`). */
    fixed?: boolean;
    className?: string;
};

/** One toast (`cf-toast` graphite pill): icon disc, message, optional action. */
export function ToastView({
    kind = 'success',
    children,
    icon,
    action,
    onDismiss,
    fixed = false,
    className,
}: ToastViewProps) {
    return (
        <div
            className={cn(
                'cf-toast',
                kind !== 'success' && `cf-toast--${kind}`,
                fixed && 'cf-toast--fixed',
                className,
            )}
            role={kind === 'error' ? 'alert' : 'status'}
        >
            <span className="cf-toast-ic">
                <Icon
                    icon={icon ?? KIND_ICON[kind]}
                    size={14}
                    strokeWidth={2}
                />
            </span>
            {children}
            {action && (
                // `text-lime` repeats the action colour: `.cf button{color:inherit}` outranks it.
                <button
                    type="button"
                    className="cf-toast-action text-lime"
                    onClick={() => {
                        action.onClick();
                        onDismiss?.();
                    }}
                >
                    {action.label}
                </button>
            )}
            {onDismiss && (
                <button
                    type="button"
                    className="ml-1 inline-flex size-6 flex-none items-center justify-center rounded-full border-0 bg-transparent p-0 text-on-graphite-muted"
                    aria-label="Dismiss"
                    onClick={onDismiss}
                >
                    <Icon icon={X} size={12} strokeWidth={2.5} />
                </button>
            )}
        </div>
    );
}

/** A live toast: leaves after its duration; hovering or focusing it pauses the clock. */
function LiveToast({ record }: { record: ToastRecord }) {
    const { id, duration } = record;
    const timer = useRef<number | null>(null);

    const stop = useCallback(() => {
        if (timer.current !== null) {
            window.clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    const start = useCallback(() => {
        stop();

        if (duration !== null) {
            timer.current = window.setTimeout(() => dismiss(id), duration);
        }
    }, [id, duration, stop]);

    useEffect(() => {
        start();

        return stop;
    }, [start, stop]);

    return (
        <div
            className="pointer-events-auto"
            onMouseEnter={stop}
            onMouseLeave={start}
            onFocus={stop}
            onBlur={start}
        >
            <ToastView
                kind={record.kind}
                icon={record.icon}
                action={record.action}
                onDismiss={() => dismiss(record.id)}
            >
                {record.message}
            </ToastView>
        </div>
    );
}

/** The top-right stack. Rendered by ToastProvider; one per app. */
export function Toaster() {
    const list = useSyncExternalStore(
        subscribe,
        () => toasts,
        () => EMPTY,
    );

    return (
        <div
            className="pointer-events-none fixed top-6 right-6 z-[60] flex w-[420px] max-w-[calc(100vw-32px)] flex-col items-end gap-2 max-sm:top-4 max-sm:right-4"
            aria-live="polite"
        >
            {list.map((record) => (
                <LiveToast key={record.id} record={record} />
            ))}
        </div>
    );
}

/** Mount once at the app root: renders children plus the toast stack. */
export function ToastProvider({ children }: { children: ReactNode }) {
    return (
        <>
            {children}
            <Toaster />
        </>
    );
}
