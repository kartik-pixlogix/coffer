import { useSyncExternalStore } from 'react';

/** Whether a media query matches, kept in sync as the window changes (false on the server). */
export function useMediaQuery(query: string): boolean {
    return useSyncExternalStore(
        (callback) => {
            const media = window.matchMedia(query);
            media.addEventListener('change', callback);

            return () => media.removeEventListener('change', callback);
        },
        () => window.matchMedia(query).matches,
        () => false,
    );
}

/** Phone width: the Mobile*.dc.html boards (390 px) apply up to here. */
export const PHONE_QUERY = '(max-width: 640px)';
