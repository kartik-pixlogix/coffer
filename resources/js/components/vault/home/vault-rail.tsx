import { Link, router } from '@inertiajs/react';
import {
    Clock,
    FolderPlus,
    Inbox,
    KeyRound,
    LayoutGrid,
    Lock,
    Star,
    TriangleAlert,
    UserPlus,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { HealthRing } from '@/components/ui/health-ring';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { TextInput } from '@/components/ui/text-input';
import { scopeCount } from '@/components/vault/home/vault-home.utils';
import type { VaultScope } from '@/components/vault/home/vault-home.utils';
import { RequestAccessModal } from '@/components/vault/share/request-access-modal';
import { cn } from '@/lib/utils';
import { requests as requestsIndex } from '@/routes/vault';
import { store as vaultStore } from '@/routes/vault/vaults';
import type {
    VaultHealthSummary,
    VaultItemRow,
    VaultSharingSummary,
    VaultSummary,
} from '@/types/vault';

type SmartFilter = {
    scope: VaultScope;
    label: string;
    icon: LucideIcon;
    /** Count chip tone (Vault.dc.html: warning for Expiring, negative for Weak). */
    tone?: 'warn' | 'neg';
};

export const SMART_FILTERS: SmartFilter[] = [
    { scope: 'fav', label: 'Favourites', icon: Star },
    { scope: 'shared', label: 'Shared with me', icon: Users },
    { scope: 'expiring', label: 'Expiring', icon: Clock, tone: 'warn' },
    { scope: 'weak', label: 'Weak', icon: TriangleAlert, tone: 'neg' },
];

/** Filters the health page links to (`?scope=reused`): not on the rail, but named in the heading. */
const HEALTH_SCOPES = {
    reused: 'Reused passwords',
    breached: 'Found in a breach',
    old: 'Older than 180 days',
    no2fa: 'Without 2FA',
} as const;

/** The list heading for a scope: "All items", a vault name, a smart filter. */
export function scopeLabel(scope: VaultScope, vaults: VaultSummary[]): string {
    if (scope === 'all') {
        return 'All items';
    }

    if (scope.startsWith('v:')) {
        return (
            vaults.find((v) => v.id === Number(scope.slice(2)))?.name ??
            'All items'
        );
    }

    return (
        SMART_FILTERS.find((f) => f.scope === scope)?.label ??
        HEALTH_SCOPES[scope as keyof typeof HEALTH_SCOPES] ??
        'All items'
    );
}

type Props = {
    vaults: VaultSummary[];
    items: VaultItemRow[];
    health: VaultHealthSummary | null;
    scope: VaultScope;
    onScope: (scope: VaultScope) => void;
    /** Locked: vault counts come from the server, smart filters have none. */
    locked: boolean;
    canAddVault: boolean;
    /** Null while locked. */
    sharing: VaultSharingSummary | null;
    onShareVault: (vault: VaultSummary) => void;
};

function RailButton({
    label,
    icon,
    on,
    count,
    tone,
    onClick,
}: {
    label: string;
    icon: LucideIcon;
    on: boolean;
    count: number | null;
    tone?: 'warn' | 'neg' | 'plain' | 'chip';
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            className="vh-rail-btn"
            aria-pressed={on}
            onClick={onClick}
        >
            <Icon icon={icon} size={16} />
            <span className="vh-rail-label">{label}</span>
            {count !== null && (
                <span
                    className={cn(
                        't-money-sm',
                        tone !== 'plain' && 'vh-rail-count',
                        tone === 'warn' && 'vh-rail-count--warn',
                        tone === 'neg' && 'vh-rail-count--neg',
                    )}
                >
                    {count}
                </span>
            )}
        </button>
    );
}

/**
 * The vault rail (Vault.dc.html aside): vaults with counts, smart filters, and the
 * graphite health card. "New vault" sits at the foot of the vault list for Admins (D68).
 */
export function VaultRail({
    vaults,
    items,
    health,
    scope,
    onScope,
    locked,
    canAddVault,
    sharing,
    onShareVault,
}: Props) {
    const [adding, setAdding] = useState(false);
    const [asking, setAsking] = useState(false);
    const selectedVault = scope.startsWith('v:')
        ? vaults.find((v) => v.id === Number(scope.slice(2)))
        : undefined;
    const canShareSelected =
        selectedVault !== undefined &&
        (sharing?.shareVaults.includes(selectedVault.id) ?? false);
    const requestable = sharing?.requestable ?? [];

    return (
        <aside className="vh-rail" aria-label="Vaults and filters">
            <span className="t-micro vh-rail-head" id="vh-vaults">
                VAULTS
            </span>
            <div
                className="vh-rail-group"
                role="group"
                aria-labelledby="vh-vaults"
            >
                <RailButton
                    label="All items"
                    icon={LayoutGrid}
                    on={scope === 'all'}
                    count={
                        locked
                            ? vaults.reduce((n, v) => n + v.count, 0)
                            : items.length
                    }
                    tone="plain"
                    onClick={() => onScope('all')}
                />
                {vaults.map((vault) => {
                    const key: VaultScope = `v:${vault.id}`;

                    return (
                        <RailButton
                            key={vault.id}
                            label={
                                vault.personal
                                    ? `${vault.name} (personal)`
                                    : vault.name
                            }
                            icon={Lock}
                            on={scope === key}
                            count={
                                locked ? vault.count : scopeCount(items, key)
                            }
                            tone="plain"
                            onClick={() => onScope(key)}
                        />
                    );
                })}
                {canAddVault && (
                    <Button
                        variant="ghost"
                        size="sm"
                        icon={FolderPlus}
                        className="vh-rail-new"
                        onClick={() => setAdding(true)}
                    >
                        New vault
                    </Button>
                )}
                {canShareSelected && selectedVault && (
                    <Button
                        variant="ghost"
                        size="sm"
                        icon={UserPlus}
                        className="vh-rail-new"
                        onClick={() => onShareVault(selectedVault)}
                    >
                        Share {selectedVault.name}
                    </Button>
                )}
                {requestable.length > 0 && sharing?.ceiling && (
                    <Button
                        variant="ghost"
                        size="sm"
                        icon={KeyRound}
                        className="vh-rail-new"
                        onClick={() => setAsking(true)}
                    >
                        Ask for access
                    </Button>
                )}
            </div>

            <span
                className="t-micro vh-rail-head vh-rail-head--gap"
                id="vh-smart"
            >
                SMART FILTERS
            </span>
            <div
                className="vh-rail-group"
                role="group"
                aria-labelledby="vh-smart"
            >
                {SMART_FILTERS.map((filter) => (
                    <RailButton
                        key={filter.scope}
                        label={filter.label}
                        icon={filter.icon}
                        on={scope === filter.scope}
                        count={locked ? null : scopeCount(items, filter.scope)}
                        tone={filter.tone ?? 'chip'}
                        onClick={() => onScope(filter.scope)}
                    />
                ))}
                {sharing !== null && sharing.pending !== null && (
                    <Link
                        href={requestsIndex().url}
                        className="vh-rail-btn vh-rail-link"
                    >
                        <Icon icon={Inbox} size={16} />
                        <span className="vh-rail-label">Access requests</span>
                        {sharing.pending > 0 && (
                            <span
                                className="t-money-sm vh-rail-count vh-rail-count--warn"
                                aria-label={`${sharing.pending} waiting`}
                            >
                                {sharing.pending}
                            </span>
                        )}
                    </Link>
                )}
            </div>

            {health && <HealthCard health={health} />}

            {canAddVault && (
                <NewVaultModal open={adding} onOpenChange={setAdding} />
            )}
            {requestable.length > 0 && sharing?.ceiling && (
                <RequestAccessModal
                    open={asking}
                    onOpenChange={setAsking}
                    target={{ kind: 'vault', vaults: requestable }}
                    ceiling={sharing.ceiling}
                />
            )}
        </aside>
    );
}

const HEALTH_ROWS: {
    key: keyof VaultHealthSummary['counts'];
    label: string;
}[] = [
    { key: 'weak', label: 'Weak passwords' },
    { key: 'reused', label: 'Reused' },
    { key: 'old', label: 'Older than 180 days' },
    { key: 'breached', label: 'Found in a breach' },
    { key: 'expiring', label: 'Expiring soon' },
];

function HealthCard({ health }: { health: VaultHealthSummary }) {
    return (
        <section className="vh-health" aria-labelledby="vh-health-t">
            <div className="cf-row" style={{ gap: 12 }}>
                {health.score !== null && (
                    <HealthRing score={health.score} tone="dark" />
                )}
                <span className="cf-stack" style={{ gap: 2 }}>
                    <span className="t-title" id="vh-health-t">
                        Vault health
                    </span>
                    <span className="t-caption vh-on-muted">
                        {health.score === null
                            ? 'No passwords scored yet'
                            : `${health.score} / 100${health.grade ? ` · ${health.grade.toLocaleLowerCase('en-IN')}` : ''}`}
                    </span>
                </span>
            </div>
            <ul className="t-caption vh-health-list">
                {HEALTH_ROWS.map((row) => (
                    <li key={row.key}>
                        <span>{row.label}</span>
                        <span className="t-money-sm">
                            {health.counts[row.key]}
                        </span>
                    </li>
                ))}
            </ul>
            <button
                type="button"
                className="cf-btn cf-btn--glass cf-btn--sm vh-health-link"
                disabled
                title="Coming soon"
                aria-label="Open health report (coming soon)"
            >
                Open health report
            </button>
        </section>
    );
}

function NewVaultModal({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [saving, setSaving] = useState(false);

    const change = (next: boolean) => {
        if (!next) {
            setName('');
            setError(undefined);
        }

        onOpenChange(next);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (name.trim() === '') {
            setError('Name the vault.');

            return;
        }

        router.post(
            vaultStore().url,
            { name: name.trim() },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => change(false),
                onError: (errors) =>
                    setError(errors.name ?? Object.values(errors)[0]),
            },
        );
    };

    return (
        <Modal
            open={open}
            onOpenChange={change}
            size="sm"
            icon={FolderPlus}
            title="New vault"
            subtitle="A shared vault for a team, client or project."
            dirty={name !== ''}
            onSubmit={submit}
            footer={
                <>
                    <Button variant="ghost" onClick={() => change(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={saving}>
                        Create vault
                    </Button>
                </>
            }
        >
            <TextInput
                label="Vault name"
                value={name}
                maxLength={80}
                placeholder="Asha Retail"
                onChange={(event) => {
                    setName(event.currentTarget.value);
                    setError(undefined);
                }}
                error={error}
                autoComplete="off"
            />
        </Modal>
    );
}
