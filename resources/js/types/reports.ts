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
/** A statement's period in the query string (StatementDate::query). */
export type StatementQuery = { fy: number; asof: string; from?: string };

export type StatementDateProps = {
    asOf: string;
    /** The period's first day (D155); the year's first day unless chosen. */
    from: string;
    fy: FiscalYearProps;
    min: string;
    max: string;
};

export type TrialBalanceAccount = {
    id: number | null;
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity' | 'income' | 'expense';
    /** Signed, debit positive (D155). */
    openingPaise: number;
    debitPaise: number;
    creditPaise: number;
    /** Signed, debit positive: opening + debit − credit. */
    closingPaise: number;
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

/** One voucher on the Journal (D154). */
export type JournalEntryRow = {
    id: number;
    voucher: string | null;
    date: string;
    type: string;
    typeLabel: string;
    memo: string | null;
    reverses: string | null;
    reversedBy: string | null;
    totalPaise: number;
    lines: {
        id: number;
        accountId: number;
        code: string;
        name: string;
        debitPaise: number;
        creditPaise: number;
    }[];
};

/** A numbered note of a Schedule III statement (D156). */
export type ScheduleNote = {
    number: number;
    title: string;
    subtitle: string | null;
    rows: {
        code: string;
        name: string;
        amount: number;
        previous: number | null;
    }[];
    total: number;
    previousTotal: number | null;
};

/** One line of the Schedule III statement of profit and loss. */
export type ScheduleProfitLine = {
    key: string;
    label: string;
    note: number | null;
    amount: number | null;
    previous: number | null;
    strong: boolean;
};

export type ScheduleProfitAndLoss = {
    lines: ScheduleProfitLine[];
    notes: ScheduleNote[];
    from: string;
    to: string;
    previousFrom: string | null;
    previousTo: string | null;
    totalIncome: number;
    totalExpenses: number;
    profit: number;
};

export type ScheduleBalanceSheet = {
    sections: {
        title: string;
        groups: {
            title: string | null;
            lines: {
                label: string;
                note: number | null;
                amount: number;
                previous: number | null;
            }[];
            total: number | null;
            previousTotal: number | null;
        }[];
        total: number;
        previousTotal: number | null;
    }[];
    notes: ScheduleNote[];
    asOf: string;
    previousAsOf: string | null;
    totalAssets: number;
    totalEquityAndLiabilities: number;
};

/** The cash flow statement (D157). */
export type CashFlowLine = { key: string; label: string; amount: number };

export type CashFlowStatement = {
    operating: CashFlowLine[];
    operatingTotal: number;
    investing: CashFlowLine[];
    investingTotal: number;
    financing: CashFlowLine[];
    financingTotal: number;
    netChange: number;
    openingCash: number;
    closingCash: number;
    reconciled: boolean;
    drift: number;
    cash: { code: string; name: string; opening: number; closing: number }[];
};
