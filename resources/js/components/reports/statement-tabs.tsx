import { cleanQuery } from '@/components/finance/fy-menu';
import { useShell } from '@/components/shell/shell-context';
import { LinkTab, LinkTabs } from '@/components/ui/tabs';
import {
    balanceSheet,
    cashFlow,
    chartOfAccounts,
    journal,
    profitAndLoss,
    trialBalance,
} from '@/routes/reports';
import type { PageKey } from '@/types/permissions';
import type { StatementQuery } from '@/types/reports';

export type StatementTab =
    | 'trial_balance'
    | 'balance_sheet'
    | 'profit_and_loss'
    | 'cash_flow'
    | 'journal'
    | 'chart_of_accounts';

type Item = {
    tab: StatementTab;
    label: string;
    page: PageKey;
    href: (query: Record<string, string | number>) => string;
};

const ITEMS: Item[] = [
    {
        tab: 'trial_balance',
        label: 'Trial balance',
        page: 'trial_balance',
        href: (query) => trialBalance({ query }).url,
    },
    {
        tab: 'balance_sheet',
        label: 'Balance sheet',
        page: 'balance_sheet',
        href: (query) => balanceSheet({ query }).url,
    },
    {
        tab: 'profit_and_loss',
        label: 'Profit and loss',
        page: 'reports',
        href: (query) => profitAndLoss({ query }).url,
    },
    {
        tab: 'cash_flow',
        label: 'Cash flow',
        page: 'cash_flow',
        href: (query) => cashFlow({ query }).url,
    },
    {
        tab: 'journal',
        label: 'Journal',
        page: 'journal',
        href: (query) => journal({ query }).url,
    },
    {
        tab: 'chart_of_accounts',
        label: 'Chart of accounts',
        page: 'chart_of_accounts',
        href: () => chartOfAccounts().url,
    },
];

/**
 * The statements' own tabs (D155–D158): the rail keeps Trial balance and Balance sheet, and
 * these tabs reach the rest without crowding it. Only pages the role may open are shown,
 * and the period travels with you between them.
 */
export function StatementTabs({
    current,
    query,
}: {
    current: StatementTab;
    query?: StatementQuery;
}) {
    const { can } = useShell();
    const items = ITEMS.filter((item) => can.pages[item.page] === true);

    if (items.length < 2) {
        return null;
    }

    const carried = cleanQuery({ ...query });

    return (
        <LinkTabs aria-label="Statements" className="sm-tabs">
            {items.map((item) => (
                <LinkTab
                    key={item.tab}
                    href={item.href(carried)}
                    current={item.tab === current}
                    preserveScroll
                >
                    {item.label}
                </LinkTab>
            ))}
        </LinkTabs>
    );
}
