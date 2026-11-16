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
    /** `?share=1` after "Save and share" (part B opens the share drawer). */
    openShare: boolean;
    abilities: { create: boolean; newVault: boolean };
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
