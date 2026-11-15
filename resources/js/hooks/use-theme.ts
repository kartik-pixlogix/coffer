import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { update as updateTheme } from '@/routes/theme';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'theme';
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60;

const listeners = new Set<() => void>();

const isTheme = (value: unknown): value is Theme =>
    value === 'light' || value === 'dark';

/** The theme the inline head script (or a previous toggle) put on <html>. */
const readTheme = (): Theme => {
    if (typeof document === 'undefined') {
        return 'light';
    }

    const value = document.documentElement.getAttribute('data-theme');

    return isTheme(value) ? value : 'light';
};

const applyTheme = (theme: Theme): void => {
    const root = document.documentElement;

    root.setAttribute('data-theme', theme);
    root.removeAttribute('data-theme-auto');
    root.style.colorScheme = theme;

    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // Storage can be unavailable (private mode); the cookie still carries it.
    }

    document.cookie = `${STORAGE_KEY}=${theme};path=/;max-age=${COOKIE_MAX_AGE};SameSite=Lax`;

    listeners.forEach((listener) => listener());
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

export type UseThemeReturn = {
    readonly theme: Theme;
    /** Apply a theme now; when signed in, also save it on the user. */
    readonly setTheme: (theme: Theme, options?: { persist?: boolean }) => void;
    readonly toggleTheme: () => void;
};

export function useTheme(): UseThemeReturn {
    const theme = useSyncExternalStore<Theme>(
        subscribe,
        readTheme,
        () => 'light',
    );

    const setTheme: UseThemeReturn['setTheme'] = (next, options) => {
        applyTheme(next);

        if (options?.persist !== false) {
            router.patch(
                updateTheme.url(),
                { theme: next },
                {
                    preserveScroll: true,
                    preserveState: true,
                    showProgress: false,
                },
            );
        }
    };

    const toggleTheme = () => setTheme(theme === 'dark' ? 'light' : 'dark');

    return { theme, setTheme, toggleTheme } as const;
}
