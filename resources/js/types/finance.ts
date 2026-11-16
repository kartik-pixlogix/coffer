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

export type CategoryOption = { id: number; name: string; active: boolean };

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
    active: boolean;
};

export type ExpenseOptions = {
    categories: CategoryOption[];
    partners: FinancePartner[];
    members: MemberOption[];
    accounts: MoneyAccountOption[];
    projects: ProjectOption[];
};
