/*
 * State and request payload of the add / edit item form (spec §14.4, VaultItemRequest).
 * Secret values only ever sit in this state while the page is open: they are never
 * prefilled on edit, and they go to the server only when typed.
 */

import type {
    VaultEditItem,
    VaultEnvironment,
    VaultItemType,
    VaultProvider,
    VaultTemplate,
    VaultTemplateField,
} from '@/types/vault';

export type CustomRow = {
    /** Local React key. */
    key: string;
    /** Server id; null for a new row. */
    id: string | null;
    label: string;
    secret: boolean;
    /** Plain value, or a newly typed secret ('' keeps a stored secret). */
    value: string;
    /** A secret value is stored. */
    set: boolean;
};

export type FormState = {
    vaultId: string;
    environment: VaultEnvironment | '';
    name: string;
    tags: string[];
    expiresAt: string | null;
    restricted: boolean;
    /** Non-secret template values by key. */
    fields: Record<string, string | boolean>;
    /** Typed secret values (template secrets and "notes"). */
    secrets: Record<string, string>;
    /** Stored secrets to remove on save. */
    clear: string[];
    custom: CustomRow[];
    attachments: File[];
    removeAttachments: number[];
};

export const ENVIRONMENTS: { value: VaultEnvironment; label: string }[] = [
    { value: 'production', label: 'Production' },
    { value: 'staging', label: 'Staging' },
    { value: 'development', label: 'Development' },
];

export const NOTES_KEY = 'notes';

let rowSeq = 0;
export const rowKey = (): string => `row-${++rowSeq}`;

/** The template's main password (scored and checked for reuse), if it has one. */
export const scoredField = (
    template: VaultTemplate | undefined,
): VaultTemplateField | undefined =>
    template?.fields.find((f) => f.primary === true && f.fingerprint === true);

/** "GitLab — " for a provider (the cursor goes to the end), '' for Custom or blank. */
export function defaultName(provider: VaultProvider | null): string {
    return provider && provider.key !== 'custom' ? `${provider.name} — ` : '';
}

export function initialState({
    template,
    provider,
    item,
    defaultVaultId,
    vaults,
}: {
    template: VaultTemplate | undefined;
    provider: VaultProvider | null;
    item: VaultEditItem | null;
    defaultVaultId: number | null;
    vaults: { id: number }[];
}): FormState {
    const fields: Record<string, string | boolean> = {};

    for (const field of template?.fields ?? []) {
        if (field.secret) {
            continue;
        }

        const stored = item?.fields[field.key];

        if (field.kind === 'toggle') {
            fields[field.key] =
                stored === true || stored === 1 || stored === '1';
        } else if (stored !== undefined && stored !== null) {
            fields[field.key] = String(stored);
        } else if (!item && field.key === 'url' && provider?.url) {
            fields[field.key] = provider.url;
        } else {
            fields[field.key] = '';
        }
    }

    const custom: CustomRow[] = item
        ? item.customFields.map((c) => ({
              key: rowKey(),
              id: c.id,
              label: c.label,
              secret: c.secret,
              value: c.secret ? '' : c.value,
              set: c.set,
          }))
        : (provider?.customFields ?? []).map((c) => ({
              key: rowKey(),
              id: null,
              label: c.label,
              secret: c.secret,
              value: '',
              set: false,
          }));

    return {
        vaultId: String(item?.vaultId ?? defaultVaultId ?? vaults[0]?.id ?? ''),
        environment: item ? (item.environment ?? '') : 'production',
        name: item?.name ?? defaultName(provider),
        tags: item?.tags ?? [],
        expiresAt: item?.expiresOn ?? null,
        restricted: item?.restricted ?? false,
        fields,
        secrets: {},
        clear: [],
        custom,
        attachments: [],
        removeAttachments: [],
    };
}

export type ItemPayload = {
    vault_id: number;
    type: VaultItemType;
    provider: string | null;
    name: string;
    environment: VaultEnvironment | null;
    tags: string[];
    expires_at: string | null;
    restricted: boolean;
    strength?: number;
    fields: Record<string, string | boolean>;
    secrets: Record<string, string>;
    clear: string[];
    custom_fields: {
        id: string | null;
        label: string;
        secret: boolean;
        value: string;
    }[];
    attachments: File[];
    remove_attachments: number[];
    share: boolean;
};

/**
 * The request body: every non-secret template field (so emptied ones clear), only the
 * secrets that were typed, and `strength` only when the main password was typed.
 * New custom rows without a value (blank, or a provider's suggestion left empty)
 * are dropped.
 */
export function buildPayload(
    state: FormState,
    {
        type,
        provider,
        template,
        canRestrict,
        strength,
        share,
    }: {
        type: VaultItemType;
        provider: string | null;
        template: VaultTemplate | undefined;
        canRestrict: boolean;
        /** zxcvbn score of the typed main password, or null. */
        strength: number | null;
        share: boolean;
    },
): ItemPayload {
    const secrets: Record<string, string> = {};

    for (const [key, value] of Object.entries(state.secrets)) {
        if (value !== '') {
            secrets[key] = value;
        }
    }

    const scored = scoredField(template);
    const payload: ItemPayload = {
        vault_id: Number(state.vaultId),
        type,
        provider,
        name: state.name.trim(),
        environment: state.environment === '' ? null : state.environment,
        tags: state.tags,
        expires_at: state.expiresAt,
        restricted: canRestrict ? state.restricted : false,
        fields: { ...state.fields },
        secrets,
        clear: state.clear.filter((key) => !(key in secrets)),
        custom_fields: state.custom
            .filter((row) => row.id !== null || row.value !== '')
            .map((row) => ({
                id: row.id,
                label: row.label.trim(),
                secret: row.secret,
                value: row.value,
            })),
        attachments: state.attachments,
        remove_attachments: state.removeAttachments,
        share,
    };

    if (scored && secrets[scored.key] !== undefined && strength !== null) {
        payload.strength = strength;
    }

    return payload;
}

/** Wipe typed secrets from a state object (on unmount / after leaving the page). */
export function wipeSecrets(state: FormState): void {
    for (const key of Object.keys(state.secrets)) {
        state.secrets[key] = '';
    }

    for (const row of state.custom) {
        if (row.secret) {
            row.value = '';
        }
    }
}
