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

export type RailItem = {
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
export function railGroups(): RailItem[][] {
    return [
        [{ label: 'Dashboard', icon: LayoutGrid, href: dashboard().url }],
        [
            { label: 'Partners', icon: ChartPie, href: null },
            { label: 'Team', icon: Users, href: null },
            { label: 'Timesheets', icon: Clock, href: null },
        ],
        [
            { label: 'Income', icon: TrendingUp, href: null },
            { label: 'Expenses', icon: Receipt, href: null },
            { label: 'Capital Accounts', icon: Landmark, href: null },
            { label: 'Partner Loans', icon: Coins, href: null },
            { label: 'Settlements', icon: ArrowLeftRight, href: null },
        ],
        [
            { label: 'Reports', icon: ChartColumn, href: null },
            { label: 'Trial Balance', icon: Scale, href: null },
            { label: 'Balance Sheet', icon: FileText, href: null },
        ],
        [{ label: 'Credentials Vault', icon: Lock, href: null }],
        [
            { label: 'Settings', icon: SlidersHorizontal, href: null },
            { label: 'Billing', icon: CreditCard, href: null },
        ],
    ];
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
