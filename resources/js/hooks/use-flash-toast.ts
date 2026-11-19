import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { dismissErrors, useToast } from '@/components/ui/toast';
import type { ToastApi } from '@/components/ui/toast';
import type { FlashToast } from '@/types/ui';

/**
 * Marks a flash event as shown. `Symbol.for` is shared by every copy of this module, so
 * even two listeners (a second app instance, a hook mounted twice) show it only once.
 */
const SHOWN = Symbol.for('coffer.flash-toast.shown');

type MarkableEvent = Event & { [SHOWN]?: true };

/**
 * Shows the toast carried by one Inertia `flash` event, once per event. The design has
 * success, error and info toasts only, so a flashed `warning` shows as info.
 *
 * @returns whether a toast was shown
 */
export function showFlashToast(event: Event, toast: ToastApi): boolean {
    const marked = event as MarkableEvent;

    if (marked[SHOWN]) {
        return false;
    }

    const flash = (event as CustomEvent<{ flash?: { toast?: FlashToast } }>)
        .detail?.flash;
    const data = flash?.toast;

    if (!data) {
        return false;
    }

    marked[SHOWN] = true;
    toast[data.type === 'warning' ? 'info' : data.type](data.message);

    return true;
}

/**
 * Shows the server's `Inertia::flash('toast', [...])` as a toast, and clears the
 * previous page's error toasts when a visit to another page starts (partial reloads,
 * like a poll, keep them).
 */
export function useFlashToast(): void {
    const toast = useToast();

    useEffect(
        () => router.on('flash', (event) => void showFlashToast(event, toast)),
        [toast],
    );

    useEffect(
        () =>
            router.on('start', (event) => {
                const { visit } = event.detail;

                if (visit.only.length === 0 && visit.method === 'get') {
                    dismissErrors();
                }
            }),
        [],
    );
}

/**
 * Mount once at the app root. It lives here, not in app.tsx: a component in the entry
 * makes React Refresh add a self-import that, after any hot update, loads a second copy
 * of the entry and boots a second app — the cause of every toast showing twice.
 */
export function FlashToasts(): null {
    useFlashToast();

    return null;
}
