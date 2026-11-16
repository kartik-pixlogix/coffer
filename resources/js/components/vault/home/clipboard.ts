/**
 * Clipboard for vault secrets (spec §14.3). A copied secret is wiped after the policy's
 * clipboard time: when the clipboard still holds it, or when the browser won't let us
 * read it (best effort). Nothing is kept in React state; only this timer's closure holds
 * the value until then.
 */

let pendingClear: number | null = null;

function cancelPendingClear(): void {
    if (pendingClear !== null) {
        window.clearTimeout(pendingClear);
        pendingClear = null;
    }
}

async function write(value: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(value);

        return true;
    } catch {
        return false;
    }
}

/** Copy a secret and schedule the wipe. Resolves false when the browser blocked it. */
export async function copySecret(
    value: string,
    clearAfterSeconds: number,
): Promise<boolean> {
    cancelPendingClear();

    if (!(await write(value))) {
        return false;
    }

    pendingClear = window.setTimeout(
        async () => {
            pendingClear = null;
            let current: string | null = null;

            try {
                current = await navigator.clipboard.readText();
            } catch {
                current = null; // not allowed to read: wipe anyway
            }

            if (current === null || current === value) {
                await write('');
            }
        },
        Math.max(1, clearAfterSeconds) * 1000,
    );

    return true;
}

/** Copy a plain (non-secret) value; it replaces any secret waiting to be wiped. */
export async function copyPlain(value: string): Promise<boolean> {
    const ok = await write(value);

    if (ok) {
        cancelPendingClear();
    }

    return ok;
}
