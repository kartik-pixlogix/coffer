import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useShell } from '@/components/shell/shell-context';
import { PageHeader } from '@/components/shell/page-header';
import {
    members as membersRoute,
    partners as partnersRoute,
    roles as rolesRoute,
    sides as sidesRoute,
} from '@/routes/settings';

export type SettingsTab =
    | 'company'
    | 'members'
    | 'roles'
    | 'partners'
    | 'sides'
    | 'categories'
    | 'notifications'
    | 'billing';

type NavItem = {
    tab: SettingsTab;
    label: string;
    /** Null until the tab's phase ships (D28). */
    href: string | null;
    jvOnly?: boolean;
};

const ITEMS: NavItem[] = [
    { tab: 'company', label: 'Company', href: null },
    { tab: 'members', label: 'Members & invites', href: membersRoute().url },
    { tab: 'roles', label: 'Roles & permissions', href: rolesRoute().url },
    { tab: 'partners', label: 'Partners & equity', href: partnersRoute().url },
    {
        tab: 'sides',
        label: 'Partner sides',
        href: sidesRoute().url,
        jvOnly: true,
    },
    { tab: 'categories', label: 'Categories', href: null },
    { tab: 'notifications', label: 'Notifications', href: null },
    { tab: 'billing', label: 'Billing', href: null },
];

type Props = {
    tab: SettingsTab;
    /** Second line of the title ("Members", "Roles"…) and the last crumb. */
    title: string;
    crumb: string;
    actions?: ReactNode;
    children: ReactNode;
};

/**
 * Settings frame (design/screens/SettingsMembers.dc.html, SettingsPartners.dc.html):
 * "Settings / {title}" page head, the vertical sub-nav card and the tab's content.
 * Tabs whose phase hasn't shipped render disabled with "coming soon" (D28).
 */
export function SettingsShell({ tab, title, crumb, actions, children }: Props) {
    const { currentCompany } = useShell();
    const items = ITEMS.filter(
        (item) => !item.jvOnly || currentCompany.type === 'jv',
    );

    return (
        <>
            <PageHeader
                kicker="Settings"
                title={title}
                crumbs={[{ title: 'Settings' }, { title: crumb }]}
                actions={actions}
            />
            <div className="settings-body">
                <nav className="cf-card snav" aria-label="Settings sections">
                    {items.map((item) =>
                        item.href === null ? (
                            <button
                                key={item.tab}
                                type="button"
                                disabled
                                title="Coming soon"
                                aria-label={`${item.label} (coming soon)`}
                            >
                                {item.label}
                            </button>
                        ) : (
                            <Link
                                key={item.tab}
                                href={item.href}
                                aria-current={
                                    item.tab === tab ? 'page' : undefined
                                }
                            >
                                {item.label}
                            </Link>
                        ),
                    )}
                </nav>
                <div className="settings-content">{children}</div>
            </div>
        </>
    );
}
