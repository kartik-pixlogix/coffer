import {
    ArrowLeftRight,
    ChartColumn,
    ChartPie,
    Clock,
    Coins,
    CreditCard,
    FileText,
    Landmark,
    LayoutGrid,
    Lock,
    Receipt,
    Scale,
    SlidersHorizontal,
    TrendingUp,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { dashboard } from '@/routes';
import { index as incomeIndex } from '@/routes/income';
import { index as partnersIndex } from '@/routes/partners';
import { members as settingsMembers } from '@/routes/settings';
import { index as teamIndex } from '@/routes/team';
import { index as timesheetsIndex } from '@/routes/timesheets';
import type { Can, PageKey } from '@/types/permissions';

export type RailItem = {
    page: PageKey;
    label: string;
    icon: LucideIcon;
    /** Null until the module's phase ships: the item renders disabled (D18). */
    href: string | null;
    /** Extra path prefixes that keep the item current (e.g. sub-pages). */
    match?: string[];
};

/**
 * The nav rail, in the groups of design/screens/Main.dc.html:
 * Dashboard · Partners, Team, Timesheets · Income, Expenses, Capital Accounts,
 * Partner Loans, Settlements · Reports, Trial Balance, Balance Sheet ·
 * Credentials Vault · Settings, Billing.
 */
const RAIL: RailItem[][] = [
    [
        {
            page: 'dashboard',
            label: 'Dashboard',
            icon: LayoutGrid,
            href: dashboard().url,
        },
    ],
    [
        {
            page: 'partners',
            label: 'Partners',
            icon: ChartPie,
            href: partnersIndex().url,
        },
        { page: 'team', label: 'Team', icon: Users, href: teamIndex().url },
        {
            page: 'timesheets',
            label: 'Timesheets',
            icon: Clock,
            href: timesheetsIndex().url,
        },
    ],
    [
        {
            page: 'income',
            label: 'Income',
            icon: TrendingUp,
            href: incomeIndex().url,
        },
        { page: 'expenses', label: 'Expenses', icon: Receipt, href: null },
        {
            page: 'capital_accounts',
            label: 'Capital Accounts',
            icon: Landmark,
            href: null,
        },
        {
            page: 'partner_loans',
            label: 'Partner Loans',
            icon: Coins,
            href: null,
        },
        {
            page: 'settlements',
            label: 'Settlements',
            icon: ArrowLeftRight,
            href: null,
        },
    ],
    [
        { page: 'reports', label: 'Reports', icon: ChartColumn, href: null },
        {
            page: 'trial_balance',
            label: 'Trial Balance',
            icon: Scale,
            href: null,
        },
        {
            page: 'balance_sheet',
            label: 'Balance Sheet',
            icon: FileText,
            href: null,
        },
    ],
    [{ page: 'vault', label: 'Credentials Vault', icon: Lock, href: null }],
    [
        {
            page: 'settings',
            label: 'Settings',
            icon: SlidersHorizontal,
            href: settingsMembers().url,
            match: ['/settings'],
        },
        { page: 'billing', label: 'Billing', icon: CreditCard, href: null },
    ],
];

/**
 * The rail for one role: only the pages the company's permission matrix lets it
 * open (spec §2.1), and Settlements only in a joint venture. Empty groups drop out.
 */
export function railGroups(
    pages: Can['pages'],
    options: { jv?: boolean } = {},
): RailItem[][] {
    return RAIL.map((group) =>
        group.filter(
            (item) =>
                pages[item.page] === true &&
                (item.page !== 'settlements' || options.jv === true),
        ),
    ).filter((group) => group.length > 0);
}

/** The rail icon for a page (also used by "What you can open"). */
export function pageIcon(page: PageKey): LucideIcon {
    return RAIL.flat().find((item) => item.page === page)?.icon ?? LayoutGrid;
}

export function isCurrent(item: RailItem, currentPath: string): boolean {
    if (item.href === null) {
        return false;
    }

    const path = new URL(item.href, 'http://x').pathname;

    return [path, ...(item.match ?? [])].some(
        (p) => currentPath === p || currentPath.startsWith(`${p}/`),
    );
}
