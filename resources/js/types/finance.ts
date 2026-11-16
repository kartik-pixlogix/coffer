/** Shared Inertia prop types for Income and Expenses (spec §9, §10). */

export type FiscalYearProps = {
    start: number;
    label: string;
    /** First and last day (ISO). */
    from: string;
    to: string;
    current: boolean;
    options: { value: number; label: string }[];
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};

export type CategoryOption = {
    id: number;
    name: string;
    active: boolean;
    system?: boolean;
};

export type FinancePartner = {
    id: number;
    name: string;
    colour: number;
    sideId: number | null;
    active: boolean;
};

export type MoneyAccountOption = {
    id: number;
    name: string;
    sideId: number | null;
    active: boolean;
};

export type ProjectOption = {
    id: number;
    name: string;
    client?: string | null;
    active: boolean;
};

export type AttachmentInfo = { name: string; size: number; url: string };

export type IncomeRow = {
    id: number;
    source: string;
    amountPaise: number;
    date: string;
    categoryId: number;
    broughtInBy: number | null;
    /** `account:{id}` or `partner:{id}`. */
    receivedInto: string;
    intoLabel: string;
    projectId: number | null;
    projectName: string | null;
    notes: string | null;
    attachment: AttachmentInfo | null;
};

export type IncomeOptions = {
    categories: CategoryOption[];
    partners: FinancePartner[];
    accounts: MoneyAccountOption[];
    projects: ProjectOption[];
    sources: string[];
};

export type ExpenseStatus = 'pending' | 'approved' | 'rejected';

export type PayerMode = 'full' | 'half' | 'custom' | 'company';

/** One payer line: who paid and where from (null account = personal card). */
export type PayerLineRow = {
    type: 'company' | 'partner' | 'member';
    partnerId: number | null;
    memberId: number | null;
    accountId: number | null;
    amountPaise: number;
    name: string;
    /** Partner colour for the avatar dot (the member's partner for members). */
    colour: number | null;
    fromLabel: string;
};

export type ExpenseRow = {
    id: number;
    description: string;
    amountPaise: number;
    date: string;
    categoryId: number;
    categoryName: string;
    projectId: number | null;
    projectName: string | null;
    billable: boolean;
    notes: string | null;
    status: ExpenseStatus;
    rejectReason: string | null;
    auto: boolean;
    mode: PayerMode;
    payers: PayerLineRow[];
    submittedBy: { name: string; colour: number | null } | null;
    decidedBy: string | null;
    decidedAt: string | null;
    receipt: AttachmentInfo | null;
    /** What the viewer may do with this row. */
    can: { edit: boolean; delete: boolean; decide: boolean };
};

export type MemberOption = {
    id: number;
    name: string;
    partnerId: number;
    colour: number | null;
    active: boolean;
};

export type ExpenseOptions = {
    categories: CategoryOption[];
    partners: FinancePartner[];
    members: MemberOption[];
    accounts: MoneyAccountOption[];
    projects: ProjectOption[];
};

/** A finance page's fiscal year, with whether it is closed (read-only, spec §4.6). */
export type ClosableFiscalYear = FiscalYearProps & { closed: boolean };

/** Capital accounts (spec §11): one partner's statement row, in paise. */
export type CapitalStatementRow = {
    partnerId: number;
    name: string;
    colour: number;
    sideId: number | null;
    sideName: string | null;
    equityBps: number;
    openingPaise: number;
    contributionsPaise: number;
    drawingsPaise: number;
    profitPaise: number;
    closingPaise: number;
};

/** A contribution or drawing in the year's ledger. */
export type CapitalMovementRow = {
    id: number;
    date: string;
    partnerId: number;
    amountPaise: number;
    note: string | null;
    accountName: string;
};

/** Partner loans (spec §12): one loan row as of the year's end. */
export type LoanRow = {
    id: number;
    code: string;
    partnerId: number;
    name: string;
    colour: number;
    date: string;
    purpose: string | null;
    converted: boolean;
    intoName: string | null;
    amountPaise: number;
    repaidPaise: number;
    outstandingPaise: number;
    instalmentPaise: number | null;
    dueOn: string | null;
    closedOn: string | null;
    repaymentsCount: number;
    next: { amountPaise: number; date: string; by: boolean } | null;
    terms: string;
    fromAccountId: number;
};

export type RepaymentRow = {
    id: number;
    loanId: number;
    code: string;
    date: string;
    partnerId: number;
    name: string;
    colour: number;
    amountPaise: number;
    fromName: string;
    note: string | null;
};

/** Settlements (spec §13): one side's row, in paise. */
export type SideSettlementRow = {
    id: number;
    name: string;
    shareBps: number;
    collectedPaise: number;
    paidPaise: number;
    netHeldPaise: number;
    pooledPaise: number;
    entitledPaise: number;
    paidOutPaise: number;
    paidInPaise: number;
    remainingPaise: number;
    status: 'owes' | 'owed' | 'settled';
};

export type SuggestedTransfer = {
    fromId: number;
    fromName: string;
    toId: number;
    toName: string;
    amountPaise: number;
};

export type SettlementTransferRow = {
    id: number;
    date: string;
    fromId: number;
    fromName: string;
    toId: number;
    toName: string;
    amountPaise: number;
    reference: string | null;
    by: string | null;
};

/** Expenses' "Owed for personal cards" card (D50). */
export type PersonalCardOwed = {
    type: 'partner' | 'member';
    id: number;
    key: string;
    name: string;
    owedPaise: number;
    colour: number | null;
    billedTo: string | null;
    accountId: number;
};

export type PersonalCardsPanel = {
    owed: PersonalCardOwed[];
    recent: {
        id: number;
        date: string;
        name: string;
        amountPaise: number;
        fromName: string;
    }[];
    accounts: MoneyAccountOption[];
};
