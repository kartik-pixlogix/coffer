import { describe, expect, it } from 'vite-plus/test';
import { buildPayload, defaultName, initialState } from './form-state';
import { addTag } from './tag-input';
import type {
    VaultEditItem,
    VaultProvider,
    VaultTemplate,
} from '@/types/vault';

const login: VaultTemplate = {
    type: 'login',
    label: 'Website login',
    expiryLabel: 'Expires on',
    fields: [
        { key: 'url', label: 'URL', kind: 'url', secret: false },
        {
            key: 'username',
            label: 'Username or email',
            kind: 'text',
            secret: false,
        },
        {
            key: 'password',
            label: 'Password',
            kind: 'secret',
            secret: true,
            primary: true,
            fingerprint: true,
        },
        {
            key: 'totp',
            label: '2FA setup key (TOTP)',
            kind: 'totp',
            secret: true,
            wide: true,
        },
    ],
};

const github: VaultProvider = {
    key: 'github',
    name: 'GitHub',
    monogram: 'GH',
    category: 'Code and deploys',
    hint: 'Login and 2FA',
    type: 'login',
    url: 'https://github.com/login',
    fills: 'username, password, 2FA and tokens',
    customFields: [{ label: 'Personal access token', secret: true }],
};

const editItem: VaultEditItem = {
    id: 7,
    vaultId: 3,
    type: 'login',
    provider: 'github',
    name: 'GitHub — vertex',
    environment: null,
    tags: ['deploy'],
    expiresOn: '2027-01-31',
    restricted: true,
    strength: 3,
    fields: { url: 'https://github.com/login', username: 'vertex' },
    secretsSet: ['password', 'notes'],
    customFields: [
        { id: 'abc123', label: 'Token', secret: true, value: '', set: true },
    ],
    attachments: [],
};

const base = {
    type: 'login' as const,
    provider: 'github',
    template: login,
    canRestrict: true,
    share: false,
};

describe('initialState', () => {
    it('pre-fills a new item from its provider', () => {
        const state = initialState({
            template: login,
            provider: github,
            item: null,
            defaultVaultId: 2,
            vaults: [{ id: 2 }],
        });
        expect(state.name).toBe('GitHub — ');
        expect(state.vaultId).toBe('2');
        expect(state.environment).toBe('production');
        expect(state.fields).toEqual({
            url: 'https://github.com/login',
            username: '',
        });
        expect(state.custom).toMatchObject([
            {
                id: null,
                label: 'Personal access token',
                secret: true,
                value: '',
            },
        ]);
        expect(state.secrets).toEqual({});
    });

    it('keeps Custom and blank items unnamed', () => {
        expect(defaultName({ ...github, key: 'custom', name: 'Custom' })).toBe(
            '',
        );
        expect(defaultName(null)).toBe('');
    });

    it('loads an item for editing without any secret', () => {
        const state = initialState({
            template: login,
            provider: github,
            item: editItem,
            defaultVaultId: 3,
            vaults: [],
        });
        expect(state).toMatchObject({
            vaultId: '3',
            environment: '',
            name: 'GitHub — vertex',
            restricted: true,
            expiresAt: '2027-01-31',
        });
        expect(state.fields).toEqual({
            url: 'https://github.com/login',
            username: 'vertex',
        });
        expect(state.secrets).toEqual({});
        expect(state.custom[0]).toMatchObject({
            id: 'abc123',
            value: '',
            set: true,
        });
    });
});

describe('buildPayload', () => {
    const state = initialState({
        template: login,
        provider: github,
        item: null,
        defaultVaultId: 2,
        vaults: [],
    });

    it('sends only typed secrets and the strength of a typed password', () => {
        const payload = buildPayload(
            {
                ...state,
                name: ' GitHub — main ',
                secrets: { password: 'pw-123', totp: '', notes: 'n' },
            },
            { ...base, strength: 4 },
        );
        expect(payload).toMatchObject({
            vault_id: 2,
            name: 'GitHub — main',
            environment: 'production',
            strength: 4,
        });
        expect(payload.secrets).toEqual({ password: 'pw-123', notes: 'n' });
        expect(payload.custom_fields).toEqual([]);
    });

    it('leaves strength out when the password wasn’t typed', () => {
        expect(
            buildPayload(state, { ...base, strength: 2 }).strength,
        ).toBeUndefined();
    });

    it('keeps cleared keys unless a new value was typed, and drops blank new rows', () => {
        const payload = buildPayload(
            {
                ...state,
                clear: ['password', 'notes'],
                secrets: { password: 'new one' },
                custom: [
                    {
                        key: 'a',
                        id: null,
                        label: '',
                        secret: false,
                        value: '',
                        set: false,
                    },
                    {
                        key: 'b',
                        id: 'abc123',
                        label: 'Token ',
                        secret: true,
                        value: '',
                        set: true,
                    },
                ],
            },
            { ...base, canRestrict: false, strength: null, share: true },
        );
        expect(payload.clear).toEqual(['notes']);
        expect(payload.custom_fields).toEqual([
            { id: 'abc123', label: 'Token', secret: true, value: '' },
        ]);
        expect(payload.restricted).toBe(false);
        expect(payload.share).toBe(true);
    });
});

describe('addTag', () => {
    it('lower-cases, skips duplicates and stops at 12', () => {
        expect(addTag(['deploy'], '  Client: Saarthi ')).toEqual([
            'deploy',
            'client: saarthi',
        ]);
        expect(addTag(['deploy'], 'DEPLOY')).toEqual(['deploy']);
        expect(addTag(['deploy'], '   ')).toEqual(['deploy']);
        const twelve = Array.from({ length: 12 }, (_, i) => `t${i}`);
        expect(addTag(twelve, 'more')).toBe(twelve);
    });
});
