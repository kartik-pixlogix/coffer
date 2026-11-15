import { Link } from '@inertiajs/react';
import { Moon, Search, Sun } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { AccountMenu } from '@/components/shell/account-menu';
import { CompanySwitcher } from '@/components/shell/company-switcher';
import { NotificationsButton } from '@/components/shell/notifications-button';
import { useTheme } from '@/hooks/use-theme';
import { formatLongDate } from '@/lib/format';
import { dashboard } from '@/routes';

function isMac(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        /Mac|iPhone|iPad/.test(navigator.userAgent)
    );
}

/**
 * ⌘K search pill. Phase 0 stub: the shortcut focuses the field; Phase 1 opens
 * the CommandPalette from it.
 */
function SearchField() {
    const inputRef = useRef<HTMLInputElement>(null);
    const [shortcut, setShortcut] = useState('⌘K');

    useEffect(() => {
        if (!isMac()) {
            setShortcut('Ctrl K');
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                inputRef.current?.focus();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <label className="cf-search">
            <Search size={18} strokeWidth={1.5} aria-hidden="true" />
            <input
                ref={inputRef}
                type="search"
                aria-label="Search or jump to"
                placeholder="Search or jump to…"
                aria-keyshortcuts="Meta+K Control+K"
            />
            <span className="cf-kbd" aria-hidden="true">
                {shortcut}
            </span>
        </label>
    );
}

function ThemeToggle() {
    const { theme, toggleTheme } = useTheme();
    const label =
        theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';

    return (
        <button
            type="button"
            className="cf-iconbtn cf-iconbtn--lg"
            aria-label={label}
            title={label}
            onClick={toggleTheme}
        >
            {theme === 'dark' ? (
                <Sun size={20} strokeWidth={1.5} aria-hidden="true" />
            ) : (
                <Moon size={20} strokeWidth={1.5} aria-hidden="true" />
            )}
        </button>
    );
}

/** Today's date, rendered on the client so it follows the viewer's clock. */
function Today() {
    const [today, setToday] = useState<string | null>(null);

    useEffect(() => setToday(formatLongDate(new Date())), []);

    return (
        <span className="t-caption t-muted mr-1" suppressHydrationWarning>
            {today}
        </span>
    );
}

export function TopBar() {
    return (
        <header className="cf-topbar">
            <div className="cf-topbar-l">
                <Link href={dashboard()} className="cf-wordmark">
                    Coffer
                </Link>
                <CompanySwitcher />
                <SearchField />
            </div>
            <div className="cf-topbar-r">
                <Today />
                <ThemeToggle />
                <NotificationsButton />
                <AccountMenu />
            </div>
        </header>
    );
}
