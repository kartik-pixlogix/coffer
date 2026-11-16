/**
 * JSON calls to the vault endpoints (reveal, copy, totp, unlock, lock, touch, reuse,
 * history) with the XSRF cookie. Secrets returned here live only in component state:
 * never put them in Inertia props, the URL, storage or logs.
 */

export type VaultResult<T> =
    | { ok: true; status: number; data: T }
    | {
          ok: false;
          status: number;
          /** 423 = vault locked, 428 = needs a fresh 2FA code, 429 = rate limited. */
          locked: boolean;
          stepUp: boolean;
          message: string;
          /** Laravel validation errors by field. */
          errors: Record<string, string[]>;
      };

function xsrfToken(): string | null {
    const raw = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return raw ? decodeURIComponent(raw) : null;
}

export async function vaultRequest<T>(
    url: string,
    body?: Record<string, unknown>,
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE' = body ? 'POST' : 'GET',
): Promise<VaultResult<T>> {
    const xsrf = xsrfToken();
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

    const response = await fetch(url, init);

    const data = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    if (response.ok) {
        return { ok: true, status: response.status, data: data as T };
    }

    const errors =
        typeof data.errors === 'object' && data.errors !== null
            ? (data.errors as Record<string, string[]>)
            : {};
    const firstError = Object.values(errors)[0]?.[0];

    return {
        ok: false,
        status: response.status,
        locked: response.status === 423 || data.locked === true,
        stepUp: response.status === 428 || data.stepUp === true,
        message:
            firstError ??
            (typeof data.message === 'string'
                ? data.message
                : response.status === 429
                  ? 'Too many requests. Wait a minute and try again.'
                  : 'Something went wrong. Try again.'),
        errors,
    };
}

/** Downloads a decrypted attachment (same checks as a reveal); resolves with the failure, if any. */
export async function downloadVaultAttachment(
    url: string,
    name: string,
): Promise<VaultResult<null>> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/octet-stream, application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as Record<
            string,
            unknown
        >;

        return {
            ok: false,
            status: response.status,
            locked: response.status === 423,
            stepUp: response.status === 428,
            message:
                typeof data.message === 'string'
                    ? data.message
                    : 'The file couldn’t be downloaded.',
            errors: {},
        };
    }

    const blob = await response.blob();
    const href = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = href;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(href), 1000);

    return { ok: true, status: response.status, data: null };
}
