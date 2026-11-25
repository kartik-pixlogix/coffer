/** The Audit trail page (App\Http\Controllers\AuditTrailController, D153). */
export type AuditFilters = {
    from: string;
    to: string;
    user: number | null;
    action: string | null;
    type: string | null;
    q: string;
    page: number;
};

export type AuditChange = {
    field: string;
    /** A sealed field (D149): only that it changed is recorded. */
    changed: boolean;
    from: string | null;
    to: string | null;
    text: string;
};

export type AuditRow = {
    id: number;
    seq: number;
    at: string;
    time: string;
    person: string;
    impersonator: string | null;
    action: string;
    actionLabel: string;
    label: string | null;
    type: string | null;
    voucher: string | null;
    ip: string | null;
    device: string | null;
    changes: AuditChange[];
    hash: string;
};

export type AuditChain = {
    ok: boolean;
    events: number;
    problem: string | null;
    checkedAt: string;
};

export type AuditTrailProps = {
    filters: AuditFilters;
    groups: { key: string; label: string }[];
    types: { value: string; label: string }[];
    people: { id: number; name: string }[];
    counts: Record<string, number>;
    rows: AuditRow[];
    total: number;
    page: number;
    pages: number;
    perPage: number;
    chain: AuditChain;
};
