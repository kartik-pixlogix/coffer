import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

/*
 * Session expiry (D30). When a request finds the session gone, the server answers with
 * 401/419 and `X-Coffer-Session: expired`. Inside the app shell the re-auth dialog opens
 * over the page, so unsaved form state survives; anywhere else the browser goes to sign-in.
 */

let expired = false;
let dialogMounted = 0;
const listeners = new Set<() => void>();

const emit = () => listeners.forEach((listener) => listener());

export function setSessionExpired(value: boolean): void {
    expired = value;
    emit();
}

/** The dialog registers itself while mounted (the app layout). */
export function registerSessionDialog(): () => void {
    dialogMounted += 1;

    return () => {
        dialogMounted -= 1;
    };
}

export function useSessionExpired(): boolean {
    return useSyncExternalStore(
        (callback) => {
            listeners.add(callback);

            return () => listeners.delete(callback);
        },
        () => expired,
        () => false,
    );
}

let installed = false;

/** Listen for the server's "session expired" answer once, for every visit. */
export function installSessionExpiryHandler(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;

    router.on('httpException', (event) => {
        const headers = event.detail.response.headers as Record<
            string,
            string | undefined
        >;

        if (headers['x-coffer-session'] !== 'expired') {
            return;
        }

        event.preventDefault();

        if (dialogMounted > 0) {
            setSessionExpired(true);
        } else {
            window.location.href = '/login';
        }
    });
}

/** JSON call for the dialog with the current XSRF cookie; returns status and body. */
export async function sessionRequest(
    url: string,
    body?: Record<string, string>,
): Promise<{ status: number; data: Record<string, unknown> }> {
    const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    const response = await fetch(url, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    return { status: response.status, data };
}
