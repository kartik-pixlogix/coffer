import { formatDate, monthShortName } from '@/lib/format';
import type {
    VaultItemRow,
    VaultItemType,
    VaultLevel,
    VaultVersion,
} from '@/types/vault';

/** Rail selection: everything, one vault (`v:{id}`) or a smart filter. */
export type VaultScope =
    | 'all'
    | 'fav'
    | 'shared'
    | 'expiring'
    | 'weak'
    | 'reused'
    | 'breached'
    | 'old'
    | 'no2fa'
    | `v:${number}`;

export type VaultSort = 'rotated' | 'name';
export type VaultView = 'list' | 'grid';

/** "Expiring" smart filter and the expiry pill: due within this many days. */
export const EXPIRING_DAYS = 30;

export const isExpiring = (item: Pick<VaultItemRow, 'expiresInDays'>) =>
    item.expiresInDays !== null && item.expiresInDays <= EXPIRING_DAYS;

export const isWeak = (item: Pick<VaultItemRow, 'strength'>) =>
    item.strength !== null && item.strength <= 1;

export function inScope(item: VaultItemRow, scope: VaultScope): boolean {
    switch (scope) {
        case 'all':
            return true;
        case 'fav':
            return item.favourite;
        case 'shared':
            return item.sharedWithMe;
        case 'expiring':
            return isExpiring(item);
        case 'weak':
            return isWeak(item);
        case 'reused':
            return item.reused;
        case 'breached':
            return item.breached;
        case 'old':
            return item.old;
        case 'no2fa':
            return item.type === 'login' && !item.hasTotp;
        default:
            return item.vaultId === Number(scope.slice(2));
    }
}

/** Search over the name, username / host, URL, tags and vault name. */
export function matchesQuery(item: VaultItemRow, query: string): boolean {
    const q = query.trim().toLocaleLowerCase('en-IN');

    if (q === '') {
        return true;
    }

    return [item.name, item.subtitle, item.url, item.vault, ...item.tags]
        .filter((part): part is string => typeof part === 'string')
        .some((part) => part.toLocaleLowerCase('en-IN').includes(q));
}

const rotatedTime = (item: VaultItemRow) =>
    item.rotatedAt === null ? -Infinity : Date.parse(item.rotatedAt);

/** "Recently rotated" (newest first, never-rotated last) or "Name A–Z". */
export function sortItems(
    items: VaultItemRow[],
    sort: VaultSort,
): VaultItemRow[] {
    const byName = (a: VaultItemRow, b: VaultItemRow) =>
        a.name.localeCompare(b.name, 'en-IN', { sensitivity: 'base' });

    return [...items].sort((a, b) =>
        sort === 'name'
            ? byName(a, b)
            : rotatedTime(b) - rotatedTime(a) || byName(a, b),
    );
}

export function visibleItems(
    items: VaultItemRow[],
    scope: VaultScope,
    query: string,
    sort: VaultSort,
): VaultItemRow[] {
    return sortItems(
        items.filter(
            (item) => inScope(item, scope) && matchesQuery(item, query),
        ),
        sort,
    );
}

export const scopeCount = (items: VaultItemRow[], scope: VaultScope) =>
    items.filter((item) => inScope(item, scope)).length;

/* --------------------------------------------------------------- strength */

export type StrengthTone = 'strong' | 'fair' | 'weak' | 'none';

export type StrengthView = {
    label: string;
    tone: StrengthTone;
    /** Bars lit out of four. */
    filled: number;
};

/**
 * Four strength bars (Vault.dc.html): 3–4 Strong (all four, chart ink), 2 Fair (two,
 * warning), 0–1 Weak (one, negative). Items without a scored password show their badge
 * ("API token", "Key file") over neutral bars.
 */
export function strengthView(
    item: Pick<VaultItemRow, 'strength' | 'badge'>,
): StrengthView {
    if (item.strength === null) {
        return { label: item.badge, tone: 'none', filled: 0 };
    }

    if (item.strength >= 3) {
        return { label: 'Strong', tone: 'strong', filled: 4 };
    }

    if (item.strength === 2) {
        return { label: 'Fair', tone: 'fair', filled: 2 };
    }

    return { label: 'Weak', tone: 'weak', filled: 1 };
}

/* ------------------------------------------------------------------ flags */

export type ItemFlagKey =
    | 'expiry'
    | 'breached'
    | 'reused'
    | 'old'
    | 'restricted';

export type ItemFlag = {
    key: ItemFlagKey;
    label: string;
    tone: 'warn' | 'neg';
};

const RENEWS: VaultItemType[] = ['hosting', 'domain', 'licence'];

const days = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`;

/** "Expires in 9 days", "Renews in 29 days", "Expires today", "Expired 3 days ago". */
export function expiryText(
    item: Pick<VaultItemRow, 'expiresInDays' | 'type'>,
): string | null {
    const n = item.expiresInDays;

    if (n === null) {
        return null;
    }

    const renews = RENEWS.includes(item.type);

    if (n < 0) {
        return `${renews ? 'Lapsed' : 'Expired'} ${days(-n)} ago`;
    }

    if (n === 0) {
        return renews ? 'Renews today' : 'Expires today';
    }

    return `${renews ? 'Renews' : 'Expires'} in ${days(n)}`;
}

/** The status pills of an item, most urgent first (always an icon and a word). */
export function itemFlags(item: VaultItemRow): ItemFlag[] {
    const flags: ItemFlag[] = [];

    if (item.breached) {
        flags.push({
            key: 'breached',
            label: 'Found in a breach',
            tone: 'neg',
        });
    }

    if (isExpiring(item)) {
        flags.push({
            key: 'expiry',
            label: expiryText(item) ?? '',
            tone: (item.expiresInDays ?? 0) <= 0 ? 'neg' : 'warn',
        });
    }

    if (item.reused) {
        flags.push({ key: 'reused', label: 'Reused', tone: 'warn' });
    }

    if (item.old) {
        flags.push({ key: 'old', label: 'Older than 180 days', tone: 'warn' });
    }

    if (item.restricted) {
        flags.push({ key: 'restricted', label: 'Admins only', tone: 'warn' });
    }

    return flags;
}

/* ------------------------------------------------------------- formatting */

export const LEVEL_LABEL: Record<VaultLevel, string> = {
    manage: 'Manage',
    edit: 'Edit',
    use: 'Use',
    view: 'View',
};

/** A link target for "Open login page": adds https:// when the URL has no scheme. */
export function ensureHttps(url: string): string {
    const trimmed = url.trim();

    return /^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed)
        ? trimmed
        : `https://${trimmed}`;
}

/** Only http(s) links open from the vault (never javascript: or data:). */
export function safeHref(url: string): string | null {
    const href = ensureHttps(url);

    try {
        const parsed = new URL(href);

        return parsed.protocol === 'https:' || parsed.protocol === 'http:'
            ? parsed.href
            : null;
    } catch {
        return null;
    }
}

/** "github.com/login" from "https://github.com/login/". */
export const displayUrl = (url: string): string =>
    url
        .trim()
        .replace(/^[a-z][a-z0-9+.-]*:\/\//i, '')
        .replace(/\/$/, '');

type Parts = {
    day: number;
    month: number;
    year: number;
    hour: string;
    minute: string;
};

function partsIn(iso: string, timeZone?: string): Parts | null {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const read = (zone?: string) => {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(date);
        const get = (type: Intl.DateTimeFormatPartTypes) =>
            parts.find((p) => p.type === type)?.value ?? '';

        return {
            day: Number(get('day')),
            month: Number(get('month')),
            year: Number(get('year')),
            hour: get('hour').padStart(2, '0'),
            minute: get('minute').padStart(2, '0'),
        };
    };

    try {
        return read(timeZone);
    } catch {
        return read();
    }
}

/** "28 Sep 2026" in the person's timezone. */
export function formatDateIn(iso: string, timeZone?: string): string {
    const p = partsIn(iso, timeZone);

    return p === null
        ? ''
        : `${p.day} ${monthShortName(p.month - 1)} ${p.year}`;
}

/** "14 Nov 2026, 18:42" in the person's timezone. */
export function formatDateTime(iso: string, timeZone?: string): string {
    const p = partsIn(iso, timeZone);

    return p === null
        ? ''
        : `${p.day} ${monthShortName(p.month - 1)} ${p.year}, ${p.hour}:${p.minute}`;
}

/** "18:42" in the person's timezone. */
export function formatTimeIn(iso: string, timeZone?: string): string {
    const p = partsIn(iso, timeZone);

    return p === null ? '' : `${p.hour}:${p.minute}`;
}

/** The activity line's second row: "14 Nov 2026, 18:42 · Chrome on macOS · 203.0.113.77". */
export function eventMeta(
    event: { at: string; device: string | null; ip: string | null },
    timeZone?: string,
): string {
    return [formatDateTime(event.at, timeZone), event.device, event.ip]
        .filter((part): part is string => !!part)
        .join(' · ');
}

/** "15 minutes", "hour", "8 hours" for "re-ask every …"; 0 = "time you open it". */
export function reaskText(minutes: number): string {
    if (minutes <= 0) {
        return 'time you open it';
    }

    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return hours === 1 ? 'hour' : `${hours} hours`;
    }

    return minutes === 1 ? 'minute' : `${minutes} minutes`;
}

/** "15 minutes", "1 hour", "8 hours". */
export function durationText(minutes: number): string {
    if (minutes % 60 === 0 && minutes >= 60) {
        const hours = minutes / 60;

        return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return `${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`;
}

/** The rotated / expiry caption in list rows: "Expires in 9 days" or "rotated 28 Sep 2026". */
export function rowWhen(item: VaultItemRow, timeZone?: string): string {
    if (isExpiring(item)) {
        return expiryText(item) ?? '';
    }

    return item.rotatedAt === null
        ? item.expiresOn
            ? `expires ${formatDate(item.expiresOn)}`
            : ''
        : `rotated ${formatDateIn(item.rotatedAt, timeZone)}`;
}

/* ----------------------------------------------------------------- timers */

/** Whole seconds until `until` (epoch ms), never below 0. */
export const secondsUntil = (until: number, now: number): number =>
    Math.max(0, Math.ceil((until - now) / 1000));

/** Whether a verification time (ISO) still lets a reveal go straight through. */
export const isVerified = (verifiedUntil: string | null, now: number) =>
    verifiedUntil !== null && Date.parse(verifiedUntil) > now;

/** The heartbeat goes out at most this often while someone works in the vault. */
export const TOUCH_EVERY_MS = 2 * 60 * 1000;

export type IdleAction = 'lock' | 'touch' | 'none';

/**
 * What the idle watcher does now: lock after `idleMs` without activity; otherwise send a
 * heartbeat when there was activity since the last one and it's been `touchEveryMs`.
 */
export function idleAction(
    now: number,
    lastActivity: number,
    lastTouch: number,
    idleMs: number,
    touchEveryMs = TOUCH_EVERY_MS,
): IdleAction {
    if (now - lastActivity >= idleMs) {
        return 'lock';
    }

    if (lastActivity > lastTouch && now - lastTouch >= touchEveryMs) {
        return 'touch';
    }

    return 'none';
}

/** One-time codes refresh by themselves for this long, then go back to "Show code". */
export const TOTP_WINDOW_MS = 2 * 60 * 1000;

/**
 * Milliseconds until the next one-time code is fetched (just after this one expires), or
 * null when that would fall outside the refresh window that started at `startedAt`.
 */
export function nextTotpDelay(
    startedAt: number,
    now: number,
    secondsLeft: number,
    windowMs = TOTP_WINDOW_MS,
): number | null {
    const delay = Math.max(0, secondsLeft * 1000) + 250;

    return now + delay - startedAt > windowMs ? null : delay;
}

/** Drops revealed values whose time is up; returns the same object when nothing changed. */
export function pruneRevealed<T extends { until: number }>(
    revealed: Record<string, T>,
    now: number,
): Record<string, T> {
    const keys = Object.keys(revealed);
    const kept = keys.filter((key) => revealed[key].until > now);

    if (kept.length === keys.length) {
        return revealed;
    }

    return Object.fromEntries(kept.map((key) => [key, revealed[key]]));
}

export type DetailRow = {
    /** Field key for reveal / copy: the template key or `custom.{id}`. */
    key: string;
    label: string;
    secret: boolean;
    /** Non-secret value (never a secret). */
    value: string | null;
    set: boolean;
    multiline: boolean;
    primary: boolean;
    /** Non-secret values that can be copied straight from the page. */
    copyable: boolean;
};

/**
 * The drawer's field rows: template fields then custom fields. One-time code setup keys
 * show as the code card instead; empty plain fields and the login URL (shown as "Open
 * login page") are left out. Secrets stay listed when empty so "Not set" is visible.
 */
export function detailRows(item: {
    url: string | null;
    fields: {
        key: string;
        label: string;
        kind: string;
        secret: boolean;
        primary: boolean;
        value: string | null;
        set: boolean;
    }[];
    customFields: {
        id: string;
        label: string;
        secret: boolean;
        value: string | null;
        set: boolean;
    }[];
}): DetailRow[] {
    const rows: DetailRow[] = [];

    for (const field of item.fields) {
        if (field.kind === 'totp') {
            continue;
        }

        if (!field.secret && (field.value === null || field.value === '')) {
            continue;
        }

        if (
            field.kind === 'url' &&
            item.url !== null &&
            field.value !== null &&
            displayUrl(field.value) === displayUrl(item.url)
        ) {
            continue;
        }

        rows.push({
            key: field.key,
            label: field.label,
            secret: field.secret,
            value: field.secret ? null : field.value,
            set: field.secret ? field.set : true,
            multiline:
                field.kind === 'textarea' || field.kind === 'secret_text',
            primary: field.primary,
            copyable: !field.secret && field.kind !== 'toggle',
        });
    }

    for (const custom of item.customFields) {
        if (!custom.secret && (custom.value === null || custom.value === '')) {
            continue;
        }

        rows.push({
            key: `custom.${custom.id}`,
            label: custom.label,
            secret: custom.secret,
            value: custom.secret ? null : custom.value,
            set: custom.secret ? custom.set : true,
            multiline: false,
            primary: false,
            copyable: !custom.secret,
        });
    }

    return rows;
}

const REASON: Record<VaultVersion['reason'], string> = {
    created: 'Created',
    edited: 'Edited',
    rotated: 'Rotated',
    restored: 'Restored',
};

/** "v3 · Rotated by Ishita Rao · 28 Sep 2026, 11:31". */
export function versionLine(version: VaultVersion, timeZone?: string): string {
    const what =
        version.reason === 'restored' && version.restoredFrom !== null
            ? `Restored from v${version.restoredFrom}`
            : REASON[version.reason];
    const who = version.by ? `${what} by ${version.by}` : what;

    return [
        `v${version.version}`,
        who,
        version.at ? formatDateTime(version.at, timeZone) : null,
    ]
        .filter(Boolean)
        .join(' · ');
}
