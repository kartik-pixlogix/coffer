import { LogOut, Moon, Search, Sun } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { signOut } from '@/components/shell/sign-out';
import {
    CommandPalette,
    isApplePlatform,
    useCommandPalette,
} from '@/components/ui/command-palette';
import type { CommandGroup } from '@/components/ui/command-palette';
import { Icon } from '@/components/ui/icon';
import { useTheme } from '@/hooks/use-theme';
import { railGroups } from '@/lib/navigation';

/** The platform never changes while the page is open. */
const subscribeNever = () => () => undefined;

/**
 * The top-bar ⌘K search pill and the command palette it opens (also on ⌘K /
 * Ctrl K anywhere). Phase 1 seeds it with the rail pages that are live plus the
 * Switch theme and Sign out actions; later phases add records, people and vault names.
 */
export function CommandMenu() {
    const { open, setOpen } = useCommandPalette();
    const { theme, toggleTheme } = useTheme();
    const shortcut = useSyncExternalStore(
        subscribeNever,
        () => (isApplePlatform() ? '⌘K' : 'Ctrl K'),
        () => '⌘K',
    );

    const groups: CommandGroup[] = [
        {
            label: 'Pages',
            items: railGroups()
                .flat()
                .flatMap((item) =>
                    item.href === null
                        ? []
                        : [
                              {
                                  id: `page:${item.label}`,
                                  label: item.label,
                                  icon: item.icon,
                                  href: item.href,
                              },
                          ],
                ),
        },
        {
            label: 'Actions',
            items: [
                {
                    id: 'action:theme',
                    label: 'Switch theme',
                    hint: theme === 'dark' ? 'to light' : 'to dark',
                    keywords: ['dark', 'light', 'appearance', 'mode'],
                    icon: theme === 'dark' ? Sun : Moon,
                    onSelect: toggleTheme,
                },
                {
                    id: 'action:sign-out',
                    label: 'Sign out',
                    keywords: ['log out', 'logout'],
                    icon: LogOut,
                    onSelect: signOut,
                },
            ],
        },
    ];

    return (
        <>
            <button
                type="button"
                className="cf-search text-left"
                aria-label="Search or jump to"
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-keyshortcuts="Meta+K Control+K"
                onClick={() => setOpen(true)}
            >
                <Icon icon={Search} size={18} />
                <span className="t-label min-w-0 flex-1 truncate text-ink-faint">
                    Search or jump to…
                </span>
                <span className="cf-kbd" aria-hidden="true">
                    {shortcut}
                </span>
            </button>
            <CommandPalette
                open={open}
                onOpenChange={setOpen}
                groups={groups}
            />
        </>
    );
}
