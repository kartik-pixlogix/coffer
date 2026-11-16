import { describe, expect, it } from 'vitest';
import {
    detailRows,
    displayUrl,
    durationText,
    ensureHttps,
    eventMeta,
    expiryText,
    formatDateIn,
    formatDateTime,
    idleAction,
    inScope,
    isVerified,
    itemFlags,
    matchesQuery,
    nextTotpDelay,
    pruneRevealed,
    reaskText,
    rowWhen,
    safeHref,
    scopeCount,
    secondsUntil,
    sortItems,
    strengthView,
    versionLine,
    visibleItems,
} from '@/components/vault/home/vault-home.utils';
import type { VaultItemRow } from '@/types/vault';

const row = (over: Partial<VaultItemRow>): VaultItemRow => ({
    id: 1,
    vaultId: 10,
    vault: 'Internal',
    name: 'GitHub — vertexlabs org',
    monogram: 'GH',
    type: 'login',
    typeLabel: 'Login',
    badge: 'Password',
    provider: 'github',
    category: 'Code and deploys',
    subtitle: 'vertexlabs-admin',
    url: 'github.com/login',
    environment: 'production',
    tags: ['ci'],
    strength: 4,
    strengthLabel: 'Very strong',
    expiresOn: null,
    expiresInDays: null,
    rotatedAt: '2026-09-28T06:01:00+00:00',
    favourite: false,
    hasTotp: true,
    restricted: false,
    breached: false,
    reused: false,
    old: false,
    sharedWithMe: false,
    level: 'manage',
    people: [],
    ...over,
});

const github = row({ id: 1, favourite: true });
const vercel = row({
    id: 2,
    name: 'Vercel — team API token',
    subtitle: 'team_vertexlabs',
    url: 'vercel.com/account/tokens',
    strength: null,
    badge: 'API token',
    expiresOn: '2026-11-25',
    expiresInDays: 9,
    rotatedAt: '2026-08-24T10:00:00+00:00',
    sharedWithMe: true,
    tags: ['deploy'],
});
const mysql = row({
    id: 3,
    vaultId: 20,
    vault: 'Saarthi Portal JV',
    name: 'MySQL — production database',
    subtitle: 'saarthi_app@10.0.4.12:3306',
    url: null,
    strength: 1,
    rotatedAt: '2026-04-09T10:00:00+00:00',
    old: true,
    tags: [],
});
const domain = row({
    id: 4,
    type: 'domain',
    name: 'Domain saarthi-jv.in',
    vaultId: 20,
    vault: 'Saarthi Portal JV',
    strength: null,
    badge: 'Domain',
    expiresInDays: 45,
    rotatedAt: null,
});
const all = [github, vercel, mysql, domain];

describe('scopes and search', () => {
    it('filters by vault and smart filter', () => {
        expect(all.filter((i) => inScope(i, 'v:20')).map((i) => i.id)).toEqual([
            3, 4,
        ]);
        expect(scopeCount(all, 'fav')).toBe(1);
        expect(scopeCount(all, 'shared')).toBe(1);
        expect(scopeCount(all, 'expiring')).toBe(1);
        expect(scopeCount(all, 'weak')).toBe(1);
        expect(scopeCount(all, 'all')).toBe(4);
    });

    it('counts expired items as expiring and zero strength as weak', () => {
        expect(inScope(row({ expiresInDays: -2 }), 'expiring')).toBe(true);
        expect(inScope(row({ strength: 0 }), 'weak')).toBe(true);
        expect(inScope(row({ strength: 2 }), 'weak')).toBe(false);
    });

    it('searches name, subtitle, url, tags and vault', () => {
        expect(matchesQuery(github, 'GITHUB')).toBe(true);
        expect(matchesQuery(mysql, '10.0.4.12')).toBe(true);
        expect(matchesQuery(vercel, 'tokens')).toBe(true);
        expect(matchesQuery(vercel, 'deploy')).toBe(true);
        expect(matchesQuery(mysql, 'saarthi portal')).toBe(true);
        expect(matchesQuery(github, 'aws')).toBe(false);
        expect(matchesQuery(github, '  ')).toBe(true);
    });

    it('sorts by rotation (never-rotated last) or name', () => {
        expect(sortItems(all, 'rotated').map((i) => i.id)).toEqual([
            1, 2, 3, 4,
        ]);
        expect(sortItems(all, 'name').map((i) => i.id)).toEqual([4, 1, 3, 2]);
        expect(
            visibleItems(all, 'v:20', 'mysql', 'name').map((i) => i.id),
        ).toEqual([3]);
    });
});

describe('strength and flags', () => {
    it('maps scores to bars and words', () => {
        expect(strengthView({ strength: 4, badge: 'x' })).toEqual({
            label: 'Strong',
            tone: 'strong',
            filled: 4,
        });
        expect(strengthView({ strength: 3, badge: 'x' }).label).toBe('Strong');
        expect(strengthView({ strength: 2, badge: 'x' })).toEqual({
            label: 'Fair',
            tone: 'fair',
            filled: 2,
        });
        expect(strengthView({ strength: 0, badge: 'x' })).toEqual({
            label: 'Weak',
            tone: 'weak',
            filled: 1,
        });
        expect(strengthView({ strength: null, badge: 'Key file' })).toEqual({
            label: 'Key file',
            tone: 'none',
            filled: 0,
        });
    });

    it('words expiry like the design', () => {
        expect(expiryText(vercel)).toBe('Expires in 9 days');
        expect(expiryText(row({ type: 'domain', expiresInDays: 29 }))).toBe(
            'Renews in 29 days',
        );
        expect(expiryText(row({ expiresInDays: 1 }))).toBe('Expires in 1 day');
        expect(expiryText(row({ expiresInDays: 0 }))).toBe('Expires today');
        expect(expiryText(row({ expiresInDays: -3 }))).toBe(
            'Expired 3 days ago',
        );
        expect(expiryText(row({ expiresInDays: null }))).toBeNull();
    });

    it('lists flags most urgent first', () => {
        const flags = itemFlags(
            row({
                breached: true,
                reused: true,
                old: true,
                restricted: true,
                expiresInDays: 5,
            }),
        );
        expect(flags.map((f) => f.label)).toEqual([
            'Found in a breach',
            'Expires in 5 days',
            'Reused',
            'Older than 180 days',
            'Admins only',
        ]);
        expect(flags[0].tone).toBe('neg');
        expect(itemFlags(domain)).toEqual([]);
    });
});

describe('formatting', () => {
    it('links to https only', () => {
        expect(ensureHttps('github.com/login')).toBe(
            'https://github.com/login',
        );
        expect(ensureHttps('http://x.test')).toBe('http://x.test');
        expect(safeHref('javascript:alert(1)')).toBeNull();
        expect(safeHref('ftp://x.test')).toBeNull();
        expect(safeHref('dash.cloudflare.com')).toBe(
            'https://dash.cloudflare.com/',
        );
        expect(displayUrl('https://github.com/login/')).toBe(
            'github.com/login',
        );
    });

    it('formats times in the person’s timezone', () => {
        expect(formatDateTime('2026-11-14T13:12:00Z', 'Asia/Kolkata')).toBe(
            '14 Nov 2026, 18:42',
        );
        expect(formatDateTime('2026-11-14T13:12:00Z', 'UTC')).toBe(
            '14 Nov 2026, 13:12',
        );
        expect(formatDateIn('2026-09-27T20:00:00Z', 'Asia/Kolkata')).toBe(
            '28 Sep 2026',
        );
        expect(formatDateTime('2026-11-14T13:12:00Z', 'Not/AZone')).not.toBe(
            '',
        );
        expect(formatDateTime('nope', 'UTC')).toBe('');
        expect(
            eventMeta(
                {
                    at: '2026-11-14T13:12:00Z',
                    device: 'Chrome on macOS',
                    ip: '203.0.113.77',
                },
                'Asia/Kolkata',
            ),
        ).toBe('14 Nov 2026, 18:42 · Chrome on macOS · 203.0.113.77');
        expect(
            eventMeta(
                { at: '2026-11-14T13:12:00Z', device: null, ip: null },
                'UTC',
            ),
        ).toBe('14 Nov 2026, 13:12');
    });

    it('words policies', () => {
        expect(reaskText(15)).toBe('15 minutes');
        expect(reaskText(60)).toBe('hour');
        expect(reaskText(480)).toBe('8 hours');
        expect(reaskText(0)).toBe('time you open it');
        expect(durationText(15)).toBe('15 minutes');
        expect(durationText(60)).toBe('1 hour');
        expect(durationText(1)).toBe('1 minute');
    });

    it('captions rows with expiry or rotation', () => {
        expect(rowWhen(vercel, 'UTC')).toBe('Expires in 9 days');
        expect(rowWhen(github, 'Asia/Kolkata')).toBe('rotated 28 Sep 2026');
        expect(rowWhen(domain, 'UTC')).toBe('');
    });
});

describe('timers', () => {
    it('counts seconds down', () => {
        expect(secondsUntil(10_500, 0)).toBe(11);
        expect(secondsUntil(0, 5_000)).toBe(0);
        expect(isVerified(null, 0)).toBe(false);
        expect(
            isVerified(
                '2026-11-16T10:15:00Z',
                Date.parse('2026-11-16T10:00:00Z'),
            ),
        ).toBe(true);
        expect(
            isVerified(
                '2026-11-16T10:15:00Z',
                Date.parse('2026-11-16T10:16:00Z'),
            ),
        ).toBe(false);
    });

    it('locks after the idle time and touches at most every two minutes', () => {
        const idle = 15 * 60_000;
        expect(idleAction(idle, 0, 0, idle)).toBe('lock');
        expect(idleAction(60_000, 30_000, 0, idle)).toBe('none');
        expect(idleAction(120_000, 30_000, 0, idle)).toBe('touch');
        expect(idleAction(300_000, 100_000, 150_000, idle)).toBe('none');
    });

    it('refreshes one-time codes for two minutes at most', () => {
        expect(nextTotpDelay(0, 0, 20)).toBe(20_250);
        expect(nextTotpDelay(0, 80_000, 30)).toBe(30_250);
        expect(nextTotpDelay(0, 100_000, 30)).toBeNull();
    });
});

describe('pruneRevealed', () => {
    it('drops expired values and keeps the object when nothing expired', () => {
        const map = { password: { until: 10 }, notes: { until: 30 } };
        expect(pruneRevealed(map, 5)).toBe(map);
        expect(pruneRevealed(map, 10)).toEqual({ notes: { until: 30 } });
        expect(pruneRevealed(map, 40)).toEqual({});
    });
});

describe('detailRows', () => {
    it('lists fields without TOTP keys, empty plain fields or the login URL', () => {
        const rows = detailRows({
            url: 'github.com/login',
            fields: [
                {
                    key: 'url',
                    label: 'URL',
                    kind: 'url',
                    secret: false,
                    primary: false,
                    value: 'https://github.com/login',
                    set: true,
                },
                {
                    key: 'username',
                    label: 'Username',
                    kind: 'text',
                    secret: false,
                    primary: false,
                    value: 'vertexlabs-admin',
                    set: true,
                },
                {
                    key: 'password',
                    label: 'Password',
                    kind: 'secret',
                    secret: true,
                    primary: true,
                    value: null,
                    set: true,
                },
                {
                    key: 'recovery',
                    label: 'Recovery codes',
                    kind: 'secret_text',
                    secret: true,
                    primary: false,
                    value: null,
                    set: false,
                },
                {
                    key: 'totp',
                    label: '2FA setup key',
                    kind: 'totp',
                    secret: true,
                    primary: false,
                    value: null,
                    set: true,
                },
                {
                    key: 'email',
                    label: 'Email',
                    kind: 'email',
                    secret: false,
                    primary: false,
                    value: null,
                    set: false,
                },
                {
                    key: 'sso',
                    label: 'SSO',
                    kind: 'toggle',
                    secret: false,
                    primary: false,
                    value: 'Off',
                    set: true,
                },
            ],
            customFields: [
                {
                    id: 'a1',
                    label: 'Webhook secret',
                    secret: true,
                    value: null,
                    set: true,
                },
                {
                    id: 'b2',
                    label: 'Org',
                    secret: false,
                    value: 'vertexlabs',
                    set: true,
                },
                {
                    id: 'c3',
                    label: 'Empty',
                    secret: false,
                    value: null,
                    set: false,
                },
            ],
        });

        expect(rows.map((r) => r.key)).toEqual([
            'username',
            'password',
            'recovery',
            'sso',
            'custom.a1',
            'custom.b2',
        ]);
        expect(rows.find((r) => r.key === 'recovery')).toMatchObject({
            set: false,
            multiline: true,
            secret: true,
        });
        expect(rows.find((r) => r.key === 'sso')?.copyable).toBe(false);
        expect(rows.find((r) => r.key === 'password')?.primary).toBe(true);
        expect(rows.every((r) => !r.secret || r.value === null)).toBe(true);
    });
});

describe('versionLine', () => {
    it('words a version like the design', () => {
        expect(
            versionLine(
                {
                    version: 3,
                    reason: 'rotated',
                    restoredFrom: null,
                    by: 'Ishita Rao',
                    at: '2026-09-28T06:01:00Z',
                    current: true,
                },
                'Asia/Kolkata',
            ),
        ).toBe('v3 · Rotated by Ishita Rao · 28 Sep 2026, 11:31');
        expect(
            versionLine(
                {
                    version: 4,
                    reason: 'restored',
                    restoredFrom: 2,
                    by: null,
                    at: null,
                    current: false,
                },
                'UTC',
            ),
        ).toBe('v4 · Restored from v2');
    });
});
