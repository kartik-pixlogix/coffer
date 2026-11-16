import type { FiscalYearProps } from '@/types/finance';

/** The period control on Dashboard and Reports (App\Services\Reports\ReportPeriod). */
export type ReportPreset =
    | 'fy'
    | 'q1'
    | 'q2'
    | 'q3'
    | 'q4'
    | 'month'
    | 'custom';

export type ReportPeriodProps = {
    preset: ReportPreset;
    /** First and last day shown (ISO). */
    from: string;
    to: string;
    /** "1 Apr – 15 Nov 2026" */
    label: string;
    /** "2026-10" for the month preset. */
    month: string | null;
    /** What trend chips compare with: "the same span of FY 2025-26", "September". */
    comparedTo: string;
    fy: FiscalYearProps;
    quarters: { value: ReportPreset; label: string; range: string }[];
    months: { value: string; label: string }[];
};

/** Query values that reproduce the period (links, exports). */
export type PeriodQuery = Record<string, string | number | null>;

/** Reports (App\Services\Reports\ProfitAndLossReport). Percentages are basis points. */
export type ProfitAndLossData = {
    company: string;
    range: string;
    fyLabel: string;
    attribution: 'per_partner' | 'jv_pooled';
    kpis: {
        income: number;
        expenses: number;
        salary: number;
        other: number;
        net: number;
        marginBps: number | null;
        expenseRatioBps: number | null;
        months: number;
        avgIncome: number;
        avgExpenses: number;
    };
    bars: { month: string; net: number; income: number; inPeriod: boolean }[];
    months: PnlMonth[];
    totals: Omit<PnlMonth, 'month'>;
    partners: PartnerPnlRow[];
    partnerTotals: {
        equityBps: number;
        revenue: number;
        expenses: number;
        other: number;
        net: number;
        profitDue: number;
    };
};

export type PnlMonth = {
    month: string;
    income: number;
    expenses: number;
    other: number;
    salary: number;
    net: number;
    marginBps: number | null;
};

export type PartnerPnlRow = {
    id: number;
    name: string;
    colour: number;
    side: string | null;
    sideShareBps: number | null;
    equityBps: number;
    revenue: number;
    expenses: number;
    other: number;
    net: number;
    profitDue: number;
    revenueShareBps: number | null;
};

export type ReportExportRow = {
    id: number;
    label: string;
    format: 'pdf' | 'xlsx';
    status: 'queued' | 'ready' | 'failed';
    url: string | null;
    createdAt: string;
};

/** "As of" and fiscal year on the statements (App\Services\Reports\StatementDate). */
export type StatementDateProps = {
    asOf: string;
    fy: FiscalYearProps;
    min: string;
    max: string;
};

export type TrialBalanceAccount = {
    id: number | null;
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity' | 'income' | 'expense';
    debitPaise: number;
    creditPaise: number;
};

export type BalanceSheetLine = { name: string; amountPaise: number };

/** "Find the drift" (App\Ledger\DriftFinder). */
export type DriftIssue = {
    kind: 'unbalanced' | 'line' | 'account';
    entryId: number | null;
    accountId: number | null;
    date: string | null;
    type: string | null;
    memo: string | null;
    account: string | null;
    amount: number;
    message: string;
};

/** Dashboard (App\Services\Dashboard\DashboardData). */
export type Trend = { paise: number; previous: number };

export type DashboardData = {
    empty: boolean;
    kpis: {
        income: Trend;
        expenses: Trend & { salary: number };
        other: Trend;
        net: Trend;
        hours: { centi: number; previous: number; members: number };
        pending: { paise: number; count: number };
    };
    balance: {
        currentPaise: number;
        openingPaise: number;
        incomePaise: number;
        expensesPaise: number;
        capitalPaise: number;
        personalPaise: number;
        otherPaise: number;
        asOf: string;
        latestMonth: { month: string; netPaise: number } | null;
    };
    months: { month: string; income: number; expenses: number; net: number }[];
    selectedMonth: number | null;
    revenueByPartner: { name: string; colour: number | null; paise: number }[];
    expensesByCategory: { name: string; paise: number }[];
    settlement: {
        transfers: { fromName: string; toName: string; amountPaise: number }[];
        sides: number;
        shares: string;
        fromEntitledPaise: number;
        fromHoldsPaise: number;
    } | null;
};

export type PendingApproval = {
    id: number;
    description: string;
    amountPaise: number;
    date: string;
    submitter: string | null;
    colour: number | null;
    canDecide: boolean;
};

/** Filled by the vault (Phase 7, D64); null hides the panel. */
export type DashboardVaultPanel = {
    items: {
        id: number;
        name: string;
        initials: string;
        expiresOn: string;
        days: number;
        href: string;
    }[];
    healthScore: number | null;
    href: string;
} | null;
