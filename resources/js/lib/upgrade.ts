/**
 * The shared "open the Upgrade modal" mechanism (D82). The server answers a create past
 * a plan limit — or any change in a read-only workspace — with a validation error under
 * `plan_limit` (a 402 for JSON calls). The app shell listens for it on every Inertia
 * visit; anything else (a banner's Upgrade button) dispatches the same window event.
 */

export const PLAN_LIMIT_KEY = 'plan_limit';

export const UPGRADE_EVENT = 'coffer:upgrade';

export type UpgradeRequest = {
    /** Why it opened ("You’ve used all 5 companies on Growth…"); none for a plain Upgrade. */
    message?: string;
};

export function openUpgrade(request: UpgradeRequest = {}): void {
    window.dispatchEvent(
        new CustomEvent<UpgradeRequest>(UPGRADE_EVENT, { detail: request }),
    );
}

/** The plan-limit message inside an Inertia `errors` bag, if there is one. */
export function planLimitMessage(errors: unknown): string | null {
    if (errors === null || typeof errors !== 'object') {
        return null;
    }

    const value = (errors as Record<string, unknown>)[PLAN_LIMIT_KEY];

    if (typeof value === 'string') {
        return value;
    }

    if (Array.isArray(value) && typeof value[0] === 'string') {
        return value[0];
    }

    return null;
}
