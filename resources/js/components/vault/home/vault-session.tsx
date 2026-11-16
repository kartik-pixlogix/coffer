import { createContext, useContext } from 'react';
import type { VaultPolicy } from '@/types/vault';

export type VaultSessionValue = {
    policy: VaultPolicy;
    /** ISO time until which reveals go through without a new code (local copy of the prop). */
    verifiedUntil: string | null;
    setVerifiedUntil: (value: string | null) => void;
    /** The server said the vault is locked (423): switch the page to the lock screen. */
    onLocked: () => void;
    /** The person's timezone, for activity and version times. */
    timeZone: string;
};

export const VaultSessionContext = createContext<VaultSessionValue | null>(
    null,
);

/** The vault page's session state (lock, step-up window, policy). */
export function useVaultSession(): VaultSessionValue {
    const value = useContext(VaultSessionContext);

    if (value === null) {
        throw new Error('useVaultSession must be used inside the vault page.');
    }

    return value;
}
