import {
    ArrowLeftRight,
    Bell,
    ChartColumn,
    ChartPie,
    Clock,
    Coins,
    CreditCard,
    FileText,
    History,
    Landmark,
    LayoutGrid,
    List,
    Lock,
    Receipt,
    Scale,
    ShieldCheck,
    SlidersHorizontal,
    TrendingUp,
    Users,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as billingIndex } from '@/routes/billing';
import { index as capitalIndex } from '@/routes/capital-accounts';
import { index as expensesIndex } from '@/routes/expenses';
import { index as incomeIndex } from '@/routes/income';
import { index as notificationsIndex } from '@/routes/notifications';
import { index as loansIndex } from '@/routes/partner-loans';
import { index as partnersIndex } from '@/routes/partners';
import { edit as editProfile } from '@/routes/profile';
import {
    balanceSheet as balanceSheetRoute,
    index as reportsIndex,
    trialBalance as trialBalanceRoute,
} from '@/routes/reports';
import { company as settingsCompany } from '@/routes/settings';
import { index as settlementsIndex } from '@/routes/settlements';
import { index as teamIndex } from '@/routes/team';
import { index as timesheetsIndex } from '@/routes/timesheets';
import {
    audit as vaultAudit,
    health as vaultHealth,
    index as vaultIndex,
} from '@/routes/vault';
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
        {
            page: 'expenses',
            label: 'Expenses',
            icon: Receipt,
            href: expensesIndex().url,
        },
        {
            page: 'capital_accounts',
            label: 'Capital Accounts',
            icon: Landmark,
            href: capitalIndex().url,
        },
        {
            page: 'partner_loans',
            label: 'Partner Loans',
            icon: Coins,
            href: loansIndex().url,
        },
        {
            page: 'settlements',
            label: 'Settlements',
            icon: ArrowLeftRight,
            href: settlementsIndex().url,
        },
    ],
    [
        {
            page: 'reports',
            label: 'Reports',
            icon: ChartColumn,
            href: reportsIndex().url,
        },
        {
            page: 'trial_balance',
            label: 'Trial Balance',
            icon: Scale,
            href: trialBalanceRoute().url,
        },
        {
            page: 'balance_sheet',
            label: 'Balance Sheet',
            icon: FileText,
            href: balanceSheetRoute().url,
        },
        {
            page: 'audit_trail',
            label: 'Audit Trail',
            icon: History,
            href: auditIndex().url,
        },
    ],
    [
        {
            page: 'vault',
            label: 'Credentials Vault',
            icon: Lock,
            href: vaultIndex().url,
            match: ['/vault'],
        },
    ],
    [
        {
            page: 'settings',
            label: 'Settings',
            icon: SlidersHorizontal,
            href: settingsCompany().url,
            match: ['/settings'],
        },
        {
            page: 'billing',
            label: 'Billing',
            icon: CreditCard,
            href: billingIndex().url,
            match: ['/billing'],
        },
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

/** One row of the phone menu (MobileNav.dc.html). */
export type PhoneMenuItem = {
    key: string;
    label: string;
    icon: LucideIcon;
    href: string;
    match?: string[];
    /** The pill at the end of the row, e.g. "3" unread (cf-pill--info). */
    badge?: { label: string; tone: 'info' | 'warn' | 'pos' | 'outline' };
};

export type PhoneMenuGroup = { title: string; items: PhoneMenuItem[] };

type PhoneMenuOptions = {
    jv?: boolean;
    /** Unread notifications, for the Notifications pill. */
    unread?: number;
    /** The role can view the vault audit log (matrix "Export and view audit log", D75). */
    vaultAudit?: boolean;
};

/** MobileNav's labels where they differ from the rail's ("Capital accounts", "Vault items"). */
const PHONE_LABELS: Partial<Record<PageKey, string>> = {
    capital_accounts: 'Capital accounts',
    partner_loans: 'Partner loans',
    reports: 'Profit and loss',
    trial_balance: 'Trial balance',
    balance_sheet: 'Balance sheet',
    audit_trail: 'Audit trail',
    vault: 'Vault items',
};

/**
 * The phone menu (design/screens/MobileNav.dc.html): every module the role may open,
 * in the board's groups, plus Notifications, the vault's Security health and Audit log,
 * and Profile and security. Empty groups drop out.
 */
export function phoneMenuGroups(
    pages: Can['pages'],
    options: PhoneMenuOptions = {},
): PhoneMenuGroup[] {
    const allowed = new Map(
        railGroups(pages, { jv: options.jv })
            .flat()
            .map((item) => [item.page, item]),
    );
    const page = (key: PageKey): PhoneMenuItem[] => {
        const item = allowed.get(key);

        return item === undefined || item.href === null
            ? []
            : [
                  {
                      key,
                      label: PHONE_LABELS[key] ?? item.label,
                      icon: item.icon,
                      href: item.href,
                      match: key === 'vault' ? undefined : item.match,
                  },
              ];
    };
    const unread = options.unread ?? 0;
    const vault = allowed.has('vault');

    const groups: PhoneMenuGroup[] = [
        {
            title: 'Overview',
            items: [
                ...page('dashboard'),
                {
                    key: 'notifications',
                    label: 'Notifications',
                    icon: Bell,
                    href: notificationsIndex().url,
                    badge:
                        unread > 0
                            ? {
                                  label: unread > 99 ? '99+' : String(unread),
                                  tone: 'info',
                              }
                            : undefined,
                },
            ],
        },
        {
            title: 'People and time',
            items: [
                ...page('partners'),
                ...page('team'),
                ...page('timesheets'),
            ],
        },
        {
            title: 'Money',
            items: [
                ...page('income'),
                ...page('expenses'),
                ...page('capital_accounts'),
                ...page('partner_loans'),
                ...page('settlements'),
            ],
        },
        {
            title: 'Reports',
            items: [
                ...page('reports'),
                ...page('trial_balance'),
                ...page('balance_sheet'),
                ...page('audit_trail'),
            ],
        },
        {
            title: 'Credentials vault',
            items: vault
                ? [
                      ...page('vault'),
                      {
                          key: 'vault_health',
                          label: 'Security health',
                          icon: ShieldCheck,
                          href: vaultHealth().url,
                      },
                      ...(options.vaultAudit
                          ? [
                                {
                                    key: 'vault_audit',
                                    label: 'Audit log',
                                    icon: List,
                                    href: vaultAudit().url,
                                },
                            ]
                          : []),
                  ]
                : [],
        },
        {
            title: 'Account',
            items: [
                ...page('settings'),
                ...page('billing'),
                {
                    key: 'profile',
                    label: 'Profile and security',
                    icon: UserRound,
                    href: editProfile().url,
                },
            ],
        },
    ];

    return groups.filter((group) => group.items.length > 0);
}

/** Rows whose label or group title contains the query (the menu's "Search or jump to"). */
export function filterPhoneMenu(
    groups: PhoneMenuGroup[],
    query: string,
): PhoneMenuGroup[] {
    const q = query.trim().toLowerCase();

    if (q === '') {
        return groups;
    }

    return groups
        .map((group) => ({
            ...group,
            items: group.title.toLowerCase().includes(q)
                ? group.items
                : group.items.filter((item) =>
                      item.label.toLowerCase().includes(q),
                  ),
        }))
        .filter((group) => group.items.length > 0);
}

export type PhoneTab = {
    key: 'home' | 'expenses' | 'hours' | 'vault';
    label: string;
    icon: LucideIcon;
    href: string;
    match?: string[];
};

/**
 * The phone tab bar (Mobile*.dc.html): Home, Expenses, Hours and Vault where the
 * role may open them; the shell adds Menu at the end.
 */
export function phoneTabs(pages: Can['pages']): PhoneTab[] {
    const tabs: Array<PhoneTab & { page: PageKey }> = [
        {
            key: 'home',
            page: 'dashboard',
            label: 'Home',
            icon: LayoutGrid,
            href: dashboard().url,
        },
        {
            key: 'expenses',
            page: 'expenses',
            label: 'Expenses',
            icon: Receipt,
            href: expensesIndex().url,
        },
        {
            key: 'hours',
            page: 'timesheets',
            label: 'Hours',
            icon: Clock,
            href: timesheetsIndex().url,
        },
        {
            key: 'vault',
            page: 'vault',
            label: 'Vault',
            icon: Lock,
            href: vaultIndex().url,
            match: ['/vault'],
        },
    ];

    return tabs
        .filter((tab) => pages[tab.page] === true)
        .map(({ page: _page, ...tab }) => tab);
}

/** Whether the current path is this phone menu row or tab (sub-pages included). */
export function isPhoneCurrent(
    item: { href: string; match?: string[] },
    currentPath: string,
): boolean {
    const path = new URL(item.href, 'http://x').pathname;

    return [path, ...(item.match ?? [])].some(
        (p) => currentPath === p || currentPath.startsWith(`${p}/`),
    );
}
