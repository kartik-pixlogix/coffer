import { useSyncExternalStore } from 'react';

const QUERY = '(prefers-reduced-motion: reduce)';

const subscribe = (callback: () => void) => {
    const media = window.matchMedia(QUERY);
    media.addEventListener('change', callback);

    return () => media.removeEventListener('change', callback);
};

/** True when the person asked the OS for less motion. Server render assumes reduced. */
export function useReducedMotion(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(QUERY).matches,
        () => true,
    );
}
