import { openUpgrade, PLAN_LIMIT_KEY } from '@/lib/upgrade';

export type JsonResult<T> =
    | { ok: true; status: number; data: T }
    | {
          ok: false;
          status: number;
          message: string;
          errors: Record<string, string[]>;
      };

function xsrfToken(): string | null {
    const raw = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return raw ? decodeURIComponent(raw) : null;
}

/**
 * A same-origin JSON call with the XSRF cookie (the bell's panel, mark read, the
 * Upgrade modal's plans). A 402 plan-limit answer opens the Upgrade modal (D82).
 */
export async function jsonRequest<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' = 'GET',
    body?: Record<string, unknown>,
): Promise<JsonResult<T>> {
    const xsrf = xsrfToken();
    let response: Response;

    try {
        const init: RequestInit = {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
            },
        };

        if (method !== 'GET') {
            init.body = JSON.stringify(body ?? {});
        }

        response = await fetch(url, init);
    } catch {
        return {
            ok: false,
            status: 0,
            message: 'You’re offline. Try again when you’re back.',
            errors: {},
        };
    }

    const data = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    if (response.ok) {
        return { ok: true, status: response.status, data: data as T };
    }

    const errors = (data.errors ?? {}) as Record<string, string[]>;
    const message =
        typeof data.message === 'string'
            ? data.message
            : 'Something went wrong. Try again.';

    if (response.status === 402 && errors[PLAN_LIMIT_KEY]) {
        openUpgrade({ message: errors[PLAN_LIMIT_KEY][0] ?? message });
    }

    return { ok: false, status: response.status, message, errors };
}
