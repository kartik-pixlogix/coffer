import { LogOut, Moon, Search, Sun } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { signOut } from '@/components/shell/sign-out';
import {
    CommandPalette,
    useCommandPalette,
} from '@/components/ui/command-palette';
import type { CommandGroup } from '@/components/ui/command-palette';
import { Icon } from '@/components/ui/icon';
import { useShortcutLabel } from '@/components/ui/search-field';
import { useTheme } from '@/hooks/use-theme';
import { usePage } from '@inertiajs/react';
import { jsonRequest } from '@/lib/json-request';
import { railGroups } from '@/lib/navigation';
import { search as searchRoute } from '@/routes';

/**
 * The top-bar ⌘K search pill and the command palette it opens (also on ⌘K /
 * Ctrl K anywhere). Phase 1 seeds it with the rail pages that are live plus the
 * Switch theme and Sign out actions; later phases add records, people and vault names.
 */
export function CommandMenu() {
    const { can, currentCompany } = usePage().props;
    const { open, setOpen } = useCommandPalette();
    const { theme, toggleTheme } = useTheme();
    const shortcut = useShortcutLabel();
    // F5 (D116): records from the server (people, partners, expenses, income, projects and
    // vault names while unlocked), fetched a moment after typing stops.
    const [found, setFound] = useState<CommandGroup[]>([]);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const onQueryChange = (query: string) => {
        window.clearTimeout(timer.current);

        if (query.trim().length < 2) {
            setFound([]);

            return;
        }

        timer.current = window.setTimeout(() => {
            void jsonRequest<{ groups: CommandGroup[] }>(
                searchRoute({ query: { q: query.trim() } }).url,
            ).then((result) => setFound(result.ok ? result.data.groups : []));
        }, 200);
    };

    const groups: CommandGroup[] = [
        {
            label: 'Pages',
            items: railGroups(can.pages, { jv: currentCompany?.type === 'jv' })
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
        ...found,
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
                <span className="t-label min-w-0 flex-1 truncate text-ink-secondary">
                    Search or jump to…
                </span>
                <span className="cf-kbd" aria-hidden="true">
                    {shortcut}
                </span>
            </button>
            <CommandPalette
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);

                    if (!next) {
                        setFound([]);
                    }
                }}
                groups={groups}
                onQueryChange={onQueryChange}
            />
        </>
    );
}
