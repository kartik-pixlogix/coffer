import { useEffect, useRef } from 'react';
import { idleAction } from '@/components/vault/home/vault-home.utils';
import { lockOnLeave, vaultRequest } from '@/lib/vault-api';
import { lock, touch } from '@/routes/vault';
import type { VaultState } from '@/types/vault';

const CHECK_EVERY_MS = 10_000;
const ACTIVITY = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const;

type Options = {
    /** Watch only while the vault is unlocked. */
    active: boolean;
    idleMinutes: number;
    /** No activity for the idle time: lock now. */
    onIdle: () => void;
    /** A heartbeat found the vault already locked (another tab, the server's own timer). */
    onLocked: () => void;
};

/**
 * Idle lock (spec §14.3): activity on the page (pointer, keys, scroll, coming back to
 * the tab) keeps the vault open with a heartbeat at most every two minutes; after the
 * policy's idle minutes without any, the vault locks.
 */
export function useIdleLock({
    active,
    idleMinutes,
    onIdle,
    onLocked,
}: Options) {
    const callbacks = useRef({ onIdle, onLocked });

    useEffect(() => {
        callbacks.current = { onIdle, onLocked };
    });

    useEffect(() => {
        if (!active) {
            return;
        }

        const idleMs = Math.max(1, idleMinutes) * 60_000;
        let lastActivity = Date.now();
        let lastTouch = Date.now();
        let done = false;

        const lockNow = () => {
            if (!done) {
                done = true;
                callbacks.current.onIdle();
            }
        };

        const check = () => {
            if (done) {
                return;
            }

            const at = Date.now();
            const action = idleAction(at, lastActivity, lastTouch, idleMs);

            if (action === 'lock') {
                lockNow();
            } else if (action === 'touch') {
                lastTouch = at;
                void vaultRequest<{ state: Pick<VaultState, 'unlocked'> }>(
                    touch().url,
                    {},
                ).then((result) => {
                    const locked = result.ok
                        ? !result.data.state.unlocked
                        : result.locked;

                    if (locked && !done) {
                        done = true;
                        callbacks.current.onLocked();
                    }
                });
            }
        };

        const mark = () => {
            lastActivity = Date.now();
        };

        // Timers sleep in background tabs: on return, lock first if the time is up.
        const onVisibility = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            if (
                idleAction(Date.now(), lastActivity, lastTouch, idleMs) ===
                'lock'
            ) {
                lockNow();
            } else {
                mark();
                check();
            }
        };

        ACTIVITY.forEach((type) =>
            window.addEventListener(type, mark, { passive: true }),
        );
        window.addEventListener('scroll', mark, {
            passive: true,
            capture: true,
        });
        document.addEventListener('visibilitychange', onVisibility);
        const timer = window.setInterval(check, CHECK_EVERY_MS);

        return () => {
            ACTIVITY.forEach((type) => window.removeEventListener(type, mark));
            window.removeEventListener('scroll', mark, { capture: true });
            document.removeEventListener('visibilitychange', onVisibility);
            window.clearInterval(timer);
        };
    }, [active, idleMinutes]);
}

/**
 * "Lock when the tab closes" (VaultLockPolicies.dc.html, D76): closing or reloading the
 * page tells the server to lock, with a keepalive request that outlives the page.
 */
export function useLockOnClose(active: boolean, enabled: boolean) {
    useEffect(() => {
        if (!active || !enabled) {
            return;
        }

        const onHide = () => lockOnLeave(lock().url);
        window.addEventListener('pagehide', onHide);

        return () => window.removeEventListener('pagehide', onHide);
    }, [active, enabled]);
}
