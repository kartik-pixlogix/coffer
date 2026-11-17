import { Link, router, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import {
    Bell,
    ChevronDown,
    ChevronRight,
    LogOut,
    Menu,
    Moon,
    Search,
    Sun,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useShell } from '@/components/shell/shell-context';
import { signOut } from '@/components/shell/sign-out';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTheme } from '@/hooks/use-theme';
import { formatCompanyMeta, formatRole } from '@/lib/format';
import {
    filterPhoneMenu,
    isPhoneCurrent,
    phoneMenuGroups,
    phoneTabs,
} from '@/lib/navigation';
import type { PhoneMenuItem } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { index as notificationsIndex } from '@/routes/notifications';

const BADGE_CLASS = {
    info: 'cf-pill--info',
    warn: 'cf-pill--warn',
    pos: 'cf-pill--pos',
    outline: 'cf-pill--outline',
} as const;

/** The unread count from the shared props (D78). */
function useUnread(): number {
    return usePage().props.notifications?.unread ?? 0;
}

function PhoneThemeButton() {
    const { theme, toggleTheme } = useTheme();

    return (
        <IconButton
            icon={theme === 'dark' ? Sun : Moon}
            aria-label={
                theme === 'dark'
                    ? 'Switch to light theme'
                    : 'Switch to dark theme'
            }
            onClick={toggleTheme}
        />
    );
}

/**
 * Phone top bar (MobileDashboard.dc.html `cf-mtop`): the company chip, which opens
 * the menu with the company list, then the theme toggle and the bell.
 */
export function PhoneTopBar({ onOpenMenu }: { onOpenMenu: () => void }) {
    const { currentCompany } = useShell();
    const unread = useUnread();

    return (
        <header className="cf-mtop app-mtop">
            <button
                type="button"
                className="cf-switcher app-mtop-co"
                aria-label={`Switch company, ${currentCompany.name}`}
                aria-haspopup="dialog"
                onClick={onOpenMenu}
            >
                <span
                    className="cf-switcher-ini app-mtop-ini"
                    aria-hidden="true"
                >
                    {currentCompany.initials}
                </span>
                <span className="t-label-strong app-mtop-name">
                    {currentCompany.name}
                </span>
                <Icon icon={ChevronDown} size={14} />
            </button>
            <div className="cf-row gap-1.5">
                <PhoneThemeButton />
                <Link
                    href={notificationsIndex()}
                    className="cf-iconbtn relative"
                    aria-label={
                        unread > 0
                            ? `Notifications, ${unread} unread`
                            : 'Notifications'
                    }
                >
                    <Icon icon={Bell} size={20} />
                    {unread > 0 && (
                        <span
                            className="cf-badge app-mtop-badge"
                            aria-hidden="true"
                        >
                            {unread > 99 ? '99+' : unread}
                        </span>
                    )}
                </Link>
            </div>
        </header>
    );
}

/**
 * Phone tab bar (`cf-mtabbar`): Home, Expenses, Hours and Vault where the role may
 * open them, then Menu. Inside the open menu, Menu is the current tab.
 */
export function PhoneTabBar({
    menuOpen,
    onMenu,
    onNavigate,
}: {
    menuOpen: boolean;
    onMenu: () => void;
    onNavigate?: () => void;
}) {
    const { can } = useShell();
    const { currentUrl } = useCurrentUrl();
    const tabs = phoneTabs(can.pages);

    return (
        <nav
            className="cf-mtabbar app-mtabbar"
            aria-label="Main"
            style={{
                gridTemplateColumns: `repeat(${tabs.length + 1}, minmax(0, 1fr))`,
            }}
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    className="cf-mtab"
                    aria-current={
                        !menuOpen && isPhoneCurrent(tab, currentUrl)
                            ? 'page'
                            : undefined
                    }
                    onClick={onNavigate}
                >
                    <span className="cf-mtab-ic">
                        <Icon icon={tab.icon} size={20} />
                    </span>
                    {tab.label}
                </Link>
            ))}
            <button
                type="button"
                className="cf-mtab"
                aria-current={menuOpen ? 'page' : undefined}
                aria-expanded={menuOpen}
                aria-haspopup="dialog"
                onClick={onMenu}
            >
                <span className="cf-mtab-ic">
                    <Icon icon={Menu} size={20} />
                </span>
                Menu
            </button>
        </nav>
    );
}

/** The row the current path belongs to: the longest matching href wins. */
function currentKey(items: PhoneMenuItem[], path: string): string | null {
    let best: { key: string; length: number } | null = null;

    for (const item of items) {
        if (isPhoneCurrent(item, path)) {
            const length = new URL(item.href, 'http://x').pathname.length;

            if (best === null || length > best.length) {
                best = { key: item.key, length };
            }
        }
    }

    return best?.key ?? null;
}

/**
 * The phone menu (design/screens/MobileNav.dc.html), a full-screen dialog: the
 * company card (opens the list to switch), "Search or jump to" over every module
 * the role may open, the dark theme switch and Sign out. Esc and Close dismiss it;
 * focus returns to the opener.
 */
export function PhoneMenu({
    open,
    onOpenChange,
    companiesOpen,
    onCompaniesOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    companiesOpen: boolean;
    onCompaniesOpenChange: (open: boolean) => void;
}) {
    const { companies, currentCompany, can, switchCompany } = useShell();
    const { auth } = usePage().props;
    const { currentUrl } = useCurrentUrl();
    const { theme, toggleTheme } = useTheme();
    const unread = useUnread();
    const [query, setQuery] = useState('');

    const groups = phoneMenuGroups(can.pages, {
        jv: currentCompany.type === 'jv',
        unread,
        vaultAudit: can.actions['vault.export'] === true,
    });
    const shown = filterPhoneMenu(groups, query);
    const current = currentKey(
        groups.flatMap((g) => g.items),
        currentUrl,
    );
    const close = () => onOpenChange(false);

    // Any visit (a row, a tab, switching company) closes the menu.
    useEffect(
        () => router.on('navigate', () => onOpenChange(false)),
        [onOpenChange],
    );

    return (
        <DialogPrimitive.Root
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setQuery('');
                }

                onOpenChange(next);
            }}
        >
            <DialogPrimitive.Portal>
                <DialogPrimitive.Content
                    className="app-menu"
                    aria-describedby={undefined}
                >
                    <header className="cf-mtop">
                        <span className="cf-row gap-2.5">
                            <span
                                className="cf-rail-logo app-menu-logo"
                                aria-hidden="true"
                            >
                                C
                            </span>
                            <span className="cf-wordmark app-menu-wordmark">
                                Coffer
                            </span>
                        </span>
                        <DialogPrimitive.Title className="cf-sr">
                            Menu
                        </DialogPrimitive.Title>
                        <DialogPrimitive.Close asChild>
                            <IconButton icon={X} aria-label="Close menu" />
                        </DialogPrimitive.Close>
                    </header>
                    <div className="cf-mscroll">
                        <section
                            aria-labelledby="pm-co-h"
                            className="app-menu-sec"
                        >
                            <h2
                                id="pm-co-h"
                                className="t-label t-muted app-menu-h"
                            >
                                Company
                            </h2>
                            <button
                                type="button"
                                className="cf-card cf-row app-menu-cur"
                                aria-expanded={companiesOpen}
                                onClick={() =>
                                    onCompaniesOpenChange(!companiesOpen)
                                }
                            >
                                <span
                                    className="cf-switcher-ini app-menu-ini"
                                    aria-hidden="true"
                                >
                                    {currentCompany.initials}
                                </span>
                                <span className="cf-stack flex-1">
                                    <span className="t-label-strong">
                                        {currentCompany.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {formatCompanyMeta(
                                            currentCompany.type,
                                            currentCompany.role,
                                        )}
                                    </span>
                                </span>
                                <Icon
                                    icon={ChevronDown}
                                    size={16}
                                    className={cn(
                                        'app-menu-chev',
                                        companiesOpen && 'is-open',
                                    )}
                                />
                            </button>
                            {companiesOpen && (
                                <div
                                    className="app-menu-list"
                                    role="group"
                                    aria-label="Switch company"
                                >
                                    {companies.map((company) => (
                                        <button
                                            key={company.id}
                                            type="button"
                                            className="app-menu-co"
                                            aria-pressed={
                                                company.id === currentCompany.id
                                            }
                                            onClick={() => {
                                                onCompaniesOpenChange(false);

                                                if (
                                                    company.id ===
                                                    currentCompany.id
                                                ) {
                                                    return;
                                                }

                                                switchCompany(company.id);
                                            }}
                                        >
                                            <span
                                                className="cf-switcher-ini app-menu-ini--sm"
                                                aria-hidden="true"
                                            >
                                                {company.initials}
                                            </span>
                                            <span className="cf-stack flex-1">
                                                <span className="t-label-strong">
                                                    {company.name}
                                                </span>
                                                <span className="t-caption t-muted">
                                                    {formatCompanyMeta(
                                                        company.type,
                                                        company.role,
                                                    )}
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </section>

                        <label className="cf-search w-full">
                            <Icon icon={Search} size={18} />
                            <input
                                type="search"
                                aria-label="Search the menu"
                                placeholder="Search or jump to"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </label>

                        {shown.map((group) => (
                            <section
                                key={group.title}
                                className="app-menu-sec"
                                aria-label={group.title}
                            >
                                <h2 className="t-label t-muted app-menu-h app-menu-h--gap">
                                    {group.title}
                                </h2>
                                <nav
                                    className="app-menu-list"
                                    aria-label={group.title}
                                >
                                    {group.items.map((item) => (
                                        <Link
                                            key={item.key}
                                            href={item.href}
                                            className="app-menu-item"
                                            aria-current={
                                                item.key === current
                                                    ? 'page'
                                                    : undefined
                                            }
                                            onClick={close}
                                        >
                                            <span className="app-menu-ic">
                                                <Icon
                                                    icon={item.icon}
                                                    size={16}
                                                />
                                            </span>
                                            <span className="t-body flex-1 font-medium">
                                                {item.label}
                                            </span>
                                            {item.badge && (
                                                <span
                                                    className={cn(
                                                        'cf-pill cf-pill--sm',
                                                        BADGE_CLASS[
                                                            item.badge.tone
                                                        ],
                                                    )}
                                                >
                                                    {item.badge.label}
                                                    <span className="cf-sr">
                                                        {' '}
                                                        unread
                                                    </span>
                                                </span>
                                            )}
                                            <Icon
                                                icon={ChevronRight}
                                                size={14}
                                                className="text-ink-faint"
                                            />
                                        </Link>
                                    ))}
                                </nav>
                            </section>
                        ))}

                        {shown.length === 0 && (
                            <div className="cf-empty" role="status">
                                <span className="t-label-strong">
                                    Nothing called “{query.trim()}”
                                </span>
                                <span className="t-caption t-muted">
                                    Try Expenses, Vault or Reports.
                                </span>
                            </div>
                        )}

                        <section
                            className="app-menu-list"
                            aria-label="Preferences"
                        >
                            <label className="cf-switch app-menu-switch">
                                <span className="cf-row gap-3">
                                    <span className="app-menu-ic">
                                        <Icon icon={Moon} size={16} />
                                    </span>
                                    <span className="t-body font-medium">
                                        Dark theme
                                    </span>
                                </span>
                                <input
                                    className="cf-sr"
                                    type="checkbox"
                                    role="switch"
                                    checked={theme === 'dark'}
                                    onChange={toggleTheme}
                                />
                                <span className="cf-switch-track" />
                            </label>
                            <button
                                type="button"
                                className="app-menu-item app-menu-out"
                                onClick={() => {
                                    close();
                                    signOut();
                                }}
                            >
                                <span className="app-menu-ic">
                                    <Icon icon={LogOut} size={16} />
                                </span>
                                <span className="t-body flex-1 font-medium">
                                    Sign out
                                </span>
                            </button>
                        </section>
                        <p className="t-caption t-muted app-menu-who">
                            Signed in as {auth.user.name} · {auth.user.email}
                            <span className="cf-sr">
                                , {formatRole(currentCompany.role)}
                            </span>
                        </p>
                    </div>
                    <PhoneTabBar menuOpen onMenu={close} onNavigate={close} />
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}
