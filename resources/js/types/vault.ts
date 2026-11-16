/**
 * Credentials Vault props (spec §14). Mirrors app/Services/Vault/ItemPresenter.php and
 * the vault controllers. No type here ever carries a secret value: secrets come only
 * from the reveal / copy / totp endpoints, one field at a time.
 */

export type VaultLevel = 'view' | 'use' | 'edit' | 'manage';
export type VaultEnvironment = 'production' | 'staging' | 'development';

export type VaultPolicy = {
    reauthMinutes: number;
    idleLockMinutes: number;
    clipboardClearSeconds: number;
    revealSeconds: number;
};

/** The session's lock and step-up state (App\Services\Vault\VaultSession::state). */
export type VaultState = {
    /** The account has 2FA on; without it the vault can't be used (spec §14.3). */
    twoFactor: boolean;
    unlocked: boolean;
    /** ISO time until which reveals don't ask for a code again; null = ask now. */
    verifiedUntil: string | null;
    /** ISO time the vault locks itself if idle. */
    lockAt: string | null;
    policy: VaultPolicy;
};

export type VaultSummary = {
    id: number;
    name: string;
    personal: boolean;
    /** Items the user can see in it. */
    count: number;
    canAdd: boolean;
};

export type VaultPerson = {
    id: number;
    name: string;
    you: boolean;
    level: VaultLevel;
    via: 'admin' | 'vault' | 'item' | 'owner';
    /** Partner colour 1–6 (--partner-N) for the avatar dot, or null. */
    colour: number | null;
};

export type VaultItemType =
    | 'login'
    | 'api_key'
    | 'server'
    | 'database'
    | 'hosting'
    | 'domain'
    | 'email'
    | 'payment'
    | 'licence'
    | 'note';

export type VaultItemRow = {
    id: number;
    vaultId: number;
    vault: string;
    name: string;
    monogram: string;
    type: VaultItemType;
    typeLabel: string;
    /** "API token", "Key file", "Domain"… for items without a scored password. */
    badge: string;
    provider: string | null;
    /** Provider category ("Code and deploys") or the type label. */
    category: string;
    /** "vertexlabs-admin", "root@203.0.113.24:22". */
    subtitle: string | null;
    url: string | null;
    environment: VaultEnvironment | null;
    tags: string[];
    /** zxcvbn score 0–4 of the main password, or null. */
    strength: number | null;
    /** "Weak" | "Fair" | "Strong" | "Very strong" | null. */
    strengthLabel: string | null;
    expiresOn: string | null;
    /** Days from today to expiry (negative = expired). */
    expiresInDays: number | null;
    rotatedAt: string | null;
    favourite: boolean;
    hasTotp: boolean;
    /** "Admins only": vault grants don't reach it. */
    restricted: boolean;
    breached: boolean;
    reused: boolean;
    /** Password older than 180 days. */
    old: boolean;
    sharedWithMe: boolean;
    level: VaultLevel | null;
    people: VaultPerson[];
};

export type VaultFieldKind =
    | 'text'
    | 'url'
    | 'email'
    | 'number'
    | 'select'
    | 'textarea'
    | 'toggle'
    | 'secret'
    | 'secret_text'
    | 'totp';

export type VaultDetailField = {
    key: string;
    label: string;
    kind: VaultFieldKind;
    secret: boolean;
    primary: boolean;
    /** Non-secret value; always null for secrets. */
    value: string | null;
    set: boolean;
};

export type VaultDetailCustomField = {
    id: string;
    label: string;
    secret: boolean;
    value: string | null;
    set: boolean;
};

export type VaultEvent = {
    id: number;
    action: string;
    /** "Rohan Desai copied the password". */
    text: string;
    at: string;
    device: string | null;
    ip: string | null;
    /** Reveal / copy / code / download: the darker timeline dot. */
    strong: boolean;
};

export type VaultAttachmentMeta = {
    id: number;
    name: string;
    size: number;
    mime?: string | null;
};

export type VaultItemDetail = VaultItemRow & {
    fields: VaultDetailField[];
    customFields: VaultDetailCustomField[];
    notesSet: boolean;
    /** Key of the main secret shown with Reveal / Copy (e.g. "password"), or null. */
    primary: string | null;
    expiryLabel: string;
    attachments: VaultAttachmentMeta[];
    activity: VaultEvent[];
    version: number;
    createdBy: string | null;
    abilities: {
        reveal: boolean;
        edit: boolean;
        delete: boolean;
        share: boolean;
    };
    /** The role allows more than the item gives: offer "Ask for more access". */
    canAsk: boolean;
    /** The most the role allows, or null. */
    ceiling: VaultLevel | null;
    /** The viewer's open request for more access, if any. */
    pendingRequest: { level: VaultLevel; createdAt: string | null } | null;
};

export type VaultHealthCounts = {
    weak: number;
    reused: number;
    old: number;
    breached: number;
    expiring: number;
    no2fa: number;
};

export type VaultHealthSummary = {
    score: number | null;
    grade: string | null;
    counts: VaultHealthCounts;
};

export type VaultIndexProps = {
    company: { name: string };
    vaultState: VaultState;
    vaults: VaultSummary[];
    /** Empty while locked. */
    items: VaultItemRow[];
    health: VaultHealthSummary | null;
    selectedId: number | null;
    selected: VaultItemDetail | null;
    /** `?share=1` after "Save and share": the share drawer opens. */
    openShare: boolean;
    /** `?scope=weak` from the health page: the rail's starting filter. */
    scope: VaultHealthKey | 'fav' | 'shared' | 'expiring' | null;
    /** Null while locked. */
    sharing: VaultSharingSummary | null;
    abilities: { create: boolean; newVault: boolean; admin: boolean };
};

export type VaultHealthKey = keyof VaultHealthCounts;

export type VaultSharingSummary = {
    /** Vaults the viewer can share (Manage). */
    shareVaults: number[];
    /** Vaults the viewer can ask for more access to. */
    requestable: { id: number; name: string; level: VaultLevel | null }[];
    ceiling: VaultLevel | null;
    /** Pending requests the viewer can decide (the inbox badge); null if they manage nothing. */
    pending: number | null;
};

/* ---- Sharing (VaultShare.dc.html) ---- */

export type VaultAccessDuration = 'never' | '24h' | '7d' | '30d';

export type VaultAccessRow = {
    userId: number;
    name: string;
    you: boolean;
    colour: number | null;
    /** "Partner · Vertex Labs", "Sr developer · team member", "Admin". */
    about: string | null;
    /** Effective level (the grant capped by the role). */
    level: VaultLevel;
    /** The grant's level, when there is one. */
    granted: VaultLevel | null;
    /** The role caps the grant. */
    limited: boolean;
    via: 'admin' | 'owner' | 'item' | 'vault';
    grantId: number | null;
    endsAt: string | null;
    editable: boolean;
};

export type VaultShareCandidate = {
    userId: number;
    name: string;
    colour: number | null;
    about: string | null;
    ceiling: VaultLevel;
    twoFactor: boolean;
};

export type VaultSharePanel = {
    target: {
        kind: 'item' | 'vault';
        id: number;
        name: string;
        vault: string;
        monogram: string;
        restricted: boolean;
    };
    access: VaultAccessRow[];
    candidates: VaultShareCandidate[];
    message: string | null;
};

export type VaultShareTarget = {
    kind: 'item' | 'vault';
    id: number;
    name: string;
};

export type VaultAccessRequestRow = {
    id: number;
    name: string;
    level: VaultLevel;
    target: string;
    targetKind: 'item' | 'vault';
    itemId: number | null;
    vault: string;
    reason: string;
    /** "for 24 hours", "no end date". */
    span: string;
    status: 'pending' | 'approved' | 'denied';
    createdAt: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    note: string | null;
};

export type VaultRequestsInbox = {
    requests: VaultAccessRequestRow[];
    mine: VaultAccessRequestRow[];
    recent: {
        id: number;
        target: string;
        who: string;
        level: VaultLevel;
        at: string | null;
    }[];
    managesAnything: boolean;
};

export type VaultRequestsProps = {
    vaultState: VaultState;
    /** Null while locked. */
    inbox: VaultRequestsInbox | null;
    tab: 'pending' | 'approved' | 'denied';
};

export type VaultVersion = {
    version: number;
    reason: 'created' | 'edited' | 'rotated' | 'restored';
    restoredFrom: number | null;
    by: string | null;
    at: string | null;
    current: boolean;
};

/* ---- Add / edit item (vault/create) ---- */

export type VaultTemplateField = {
    key: string;
    label: string;
    kind: VaultFieldKind;
    secret: boolean;
    primary?: boolean;
    fingerprint?: boolean;
    options?: string[];
    placeholder?: string;
    /** Spans both form columns. */
    wide?: boolean;
};

export type VaultTemplate = {
    type: VaultItemType;
    label: string;
    expiryLabel: string;
    fields: VaultTemplateField[];
};

export type VaultProvider = {
    key: string;
    name: string;
    monogram: string;
    category: string;
    hint: string;
    type: VaultItemType;
    url: string;
    /** "URL, username, password and 2FA" for "Next you'll fill in …". */
    fills: string;
    customFields: { label: string; secret: boolean }[];
    /** Items already saved with this provider (catalog only). */
    saved?: number;
};

export type VaultEditCustomField = {
    id: string | null;
    label: string;
    secret: boolean;
    /** Plain value; '' for secret fields (an empty secret keeps the stored one). */
    value: string;
    /** A secret value is stored. */
    set: boolean;
};

export type VaultEditItem = {
    id: number;
    vaultId: number;
    type: VaultItemType;
    provider: string | null;
    name: string;
    environment: VaultEnvironment | null;
    tags: string[];
    expiresOn: string | null;
    restricted: boolean;
    strength: number | null;
    /** Non-secret template values by key. */
    fields: Record<string, string | number | boolean>;
    /** Secret template fields (and "notes") that hold a value. */
    secretsSet: string[];
    customFields: VaultEditCustomField[];
    attachments: VaultAttachmentMeta[];
};

export type VaultCreateProps = {
    catalog: (VaultProvider & { saved: number })[];
    categories: string[];
    types: { type: VaultItemType; label: string }[];
    templates: VaultTemplate[];
    vaults: { id: number; name: string }[];
    canRestrict: boolean;
    attachmentMaxKb: number;
    step: 'catalog' | 'details';
    provider: VaultProvider | null;
    type: VaultItemType | null;
    item: VaultEditItem | null;
    defaultVaultId: number | null;
    /** Opened from Rotate: the generator starts open. */
    rotate: boolean;
};

/* ---- JSON endpoints ---- */

export type VaultRevealResponse = {
    field: string;
    label: string;
    value: string;
    hideAfter: number;
    clearAfter: number;
};

export type VaultTotpResponse = {
    code: string;
    secondsLeft: number;
    period: number;
};

/** 423 = locked, 428 = needs a fresh 2FA code (step-up), 429 = rate limited. */
export type VaultBlockedResponse = {
    message: string;
    locked?: boolean;
    stepUp?: boolean;
};

export type VaultUnlockResponse = {
    ok: true;
    state: Pick<VaultState, 'unlocked' | 'verifiedUntil' | 'lockAt'>;
};

export type VaultReuseResponse = {
    matches: { id: number; name: string }[];
    checked: number;
};

/* ---- Offboarding (spec §14.2, D73) ---- */

export type VaultOffboardingRow = {
    id: number;
    name: string;
    /** "was removed", "lost vault access", "was deactivated". */
    reason: string;
    at: string;
    total: number;
    /** Items not rotated since. */
    open: number;
    /** Items the viewer can't see (counted, not named). */
    hidden: number;
    items: {
        id: number;
        name: string;
        monogram: string;
        vault: string;
        rotated: boolean;
        canRotate: boolean;
    }[];
};

/* ---- Security health (VaultHealth.dc.html, D74) ---- */

export type VaultHealthRow = {
    id: number;
    name: string;
    monogram: string;
    vault: string;
    /** "Last changed 9 Apr 2026 · 220 days ago". */
    detail: string;
    /** The viewer can Edit it (Rotate / Renew / Add 2FA). */
    canFix: boolean;
};

export type VaultHealthList = {
    key: VaultHealthKey;
    title: string;
    why: string;
    /** "Rotate", "Rotate now", "Renew", "Add 2FA". */
    action: string;
    rows: VaultHealthRow[];
};

export type VaultHealthReport = {
    score: number | null;
    grade: string | null;
    counts: VaultHealthCounts;
    issues: number;
    /** Items with at least one issue. */
    affected: number;
    total: number;
    /** The category whose fixes raise the score most. */
    biggest: VaultHealthKey | null;
    /** Last breach check, ISO. */
    checkedAt: string | null;
    lists: VaultHealthList[];
};

export type VaultHealthProps = {
    vaultState: VaultState;
    /** Null while locked. */
    report: VaultHealthReport | null;
    offboardings: VaultOffboardingRow[];
    filter: VaultHealthKey | null;
    breachChecks: boolean;
    abilities: { admin: boolean };
};

/* ---- Audit log (VaultAudit.dc.html, D75) ---- */

export type VaultAuditFilters = {
    range: 'today' | '7d' | '30d' | 'custom';
    from: string;
    to: string;
    user: number | null;
    /** An action group key ("revealed", "edited"…). */
    action: string | null;
    q: string;
    item: number | null;
    page: number;
};

export type VaultAuditRow = {
    id: number;
    at: string;
    user: string | null;
    colour: number | null;
    action: string;
    label: string;
    field: string | null;
    item: string | null;
    itemId: number | null;
    ip: string | null;
    device: string | null;
    newDevice: boolean;
};

export type VaultAuditLog = {
    kpis: { events: number; reveals: number; shares: number; newDevices: number };
    /** Events per action group (and "all") under the other filters. */
    counts: Record<string, number>;
    rows: VaultAuditRow[];
    total: number;
    page: number;
    pages: number;
    perPage: number;
    people: { id: number; name: string }[];
};

export type VaultAuditProps = {
    vaultState: VaultState;
    filters: VaultAuditFilters;
    /** Null while locked. */
    log: VaultAuditLog | null;
    groups: { key: string; label: string }[];
};
