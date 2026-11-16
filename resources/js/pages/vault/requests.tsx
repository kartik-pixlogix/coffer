import { Head, router, usePage } from '@inertiajs/react';
import { Check, Inbox, X } from 'lucide-react';
import { useState } from 'react';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Modal } from '@/components/ui/modal';
import { StatusPill } from '@/components/ui/status-pill';
import { Tab, TabPanel, Tabs, TabsList } from '@/components/ui/tabs';
import { TextInput } from '@/components/ui/text-input';
import { PageHeader } from '@/components/shell/page-header';
import { LevelPill } from '@/components/vault/home/vault-bits';
import {
    formatDateIn,
    LEVEL_LABEL,
} from '@/components/vault/home/vault-home.utils';
import { VaultGate } from '@/components/vault/vault-gate';
import { index as vaultIndex } from '@/routes/vault';
import { approve, deny, destroy as withdraw } from '@/routes/vault/requests';
import type {
    VaultAccessRequestRow,
    VaultRequestsInbox,
    VaultRequestsProps,
} from '@/types/vault';

type TabKey = 'pending' | 'approved' | 'denied';

const TABS: { key: TabKey; label: string }[] = [
    { key: 'pending', label: 'Pending' },
    { key: 'approved', label: 'Approved' },
    { key: 'denied', label: 'Denied' },
];

/** "2 hours ago", "Yesterday, 18:40", "12 Nov". */
function when(iso: string | null, timeZone?: string): string {
    if (iso === null) {
        return '';
    }

    const ms = Date.now() - Date.parse(iso);
    const hours = Math.floor(ms / 3_600_000);

    if (ms < 60_000) {
        return 'Just now';
    }

    if (hours < 1) {
        const m = Math.floor(ms / 60_000);

        return `${m} ${m === 1 ? 'minute' : 'minutes'} ago`;
    }

    if (hours < 24) {
        return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
    }

    return formatDateIn(iso, timeZone);
}

/**
 * Sharing and access requests (VaultShare.dc.html, spec §14.2): requests the viewer can
 * decide, by status, with Approve and Deny; your own open requests; recently shared.
 */
export default function VaultRequests({
    vaultState,
    inbox,
    tab: initialTab,
}: VaultRequestsProps) {
    return (
        <>
            <Head title="Sharing" />
            <PageHeader
                kicker="Vault"
                title="Sharing"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Sharing' },
                ]}
            />
            <VaultGate vaultState={vaultState}>
                {inbox && (
                    <RequestsInbox inbox={inbox} initialTab={initialTab} />
                )}
            </VaultGate>
        </>
    );
}

function RequestsInbox({
    inbox,
    initialTab,
}: {
    inbox: VaultRequestsInbox;
    initialTab: TabKey;
}) {
    const { auth } = usePage().props;
    const timeZone = auth.user.timezone;
    const [tab, setTab] = useState<TabKey>(initialTab);
    const [busy, setBusy] = useState<number | null>(null);
    const [denying, setDenying] = useState<VaultAccessRequestRow | null>(null);
    const counts = TABS.reduce<Record<TabKey, number>>(
        (acc, t) => ({
            ...acc,
            [t.key]: inbox.requests.filter((r) => r.status === t.key).length,
        }),
        { pending: 0, approved: 0, denied: 0 },
    );
    const shown = inbox.requests.filter((r) => r.status === tab);
    const mineOpen = inbox.mine.filter((r) => r.status === 'pending');

    const decide = (row: VaultAccessRequestRow) =>
        router.post(
            approve(row.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(row.id),
                onFinish: () => setBusy(null),
            },
        );

    return (
        <div className="cf-stack vq-page">
            <Tabs
                value={tab}
                onValueChange={(v) => setTab(v as TabKey)}
                asChild
            >
                <section
                    className="cf-card cf-stack vq-card"
                    aria-labelledby="vq-h"
                >
                    <div className="cf-kv vq-head">
                        <h2 id="vq-h" className="cf-dot-title t-title">
                            Access requests
                        </h2>
                        <TabsList aria-label="Requests">
                            {TABS.map((t) => (
                                <Tab
                                    key={t.key}
                                    value={t.key}
                                    count={counts[t.key]}
                                >
                                    {t.label}
                                </Tab>
                            ))}
                        </TabsList>
                    </div>
                    <TabPanel value={tab} className="cf-stack vq-list">
                        {shown.map((r) => (
                            <article
                                key={r.id}
                                className="vq-rq"
                                aria-label={`${r.name} requests ${LEVEL_LABEL[r.level]} access to ${r.target}`}
                            >
                                <Avatar name={r.name} graphite label={false} />
                                <div className="vq-rq-text">
                                    <div className="t-body">
                                        <span className="t-label-strong">
                                            {r.name}
                                        </span>{' '}
                                        wants{' '}
                                        <StatusPill tone="outline" size="sm">
                                            {LEVEL_LABEL[r.level]}
                                        </StatusPill>{' '}
                                        access to{' '}
                                        <span className="t-label-strong">
                                            {r.targetKind === 'vault'
                                                ? `the ${r.target} vault`
                                                : r.target}
                                        </span>
                                    </div>
                                    <div className="t-caption t-muted vq-meta">
                                        {when(r.createdAt, timeZone)} · {r.span}{' '}
                                        · {r.vault} vault
                                    </div>
                                    <p className="vq-quote">“{r.reason}”</p>
                                    {r.status !== 'pending' && (
                                        <div className="t-caption vq-decided">
                                            <StatusPill
                                                tone={
                                                    r.status === 'approved'
                                                        ? 'pos'
                                                        : 'neg'
                                                }
                                                icon={
                                                    r.status === 'approved'
                                                        ? Check
                                                        : X
                                                }
                                                size="sm"
                                            >
                                                {r.status === 'approved'
                                                    ? 'Approved'
                                                    : 'Denied'}
                                            </StatusPill>{' '}
                                            <span className="t-muted">
                                                {r.decidedBy
                                                    ? `by ${r.decidedBy}`
                                                    : ''}
                                                {r.decidedAt
                                                    ? ` on ${formatDateIn(r.decidedAt, timeZone)}`
                                                    : ''}
                                                {r.note ? ` · ${r.note}` : ''}
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <div className="cf-row vq-acts">
                                    {r.status === 'pending' && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                aria-label={`Deny ${r.name} for ${r.target}`}
                                                onClick={() => setDenying(r)}
                                            >
                                                Deny
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="primary"
                                                loading={busy === r.id}
                                                aria-label={`Approve ${r.name} for ${r.target}`}
                                                onClick={() => decide(r)}
                                            >
                                                Approve
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </article>
                        ))}
                        {shown.length === 0 && (
                            <EmptyState
                                icon={Check}
                                title={
                                    tab === 'pending'
                                        ? 'Nothing waiting'
                                        : `Nothing ${tab} yet`
                                }
                                description={
                                    inbox.managesAnything
                                        ? 'New requests show up here and in your notifications.'
                                        : 'Requests for items and vaults you manage show up here.'
                                }
                            />
                        )}
                    </TabPanel>
                </section>
            </Tabs>

            {mineOpen.length > 0 && (
                <section
                    className="cf-card cf-stack vq-card"
                    aria-labelledby="vq-mine"
                >
                    <h2 id="vq-mine" className="cf-dot-title t-title">
                        Your requests
                    </h2>
                    {mineOpen.map((r) => (
                        <div key={r.id} className="cf-inset vq-mine">
                            <span className="t-label-strong">{r.target}</span>
                            <LevelPill level={r.level} />
                            <span className="t-caption t-muted">
                                {when(r.createdAt, timeZone)} · {r.span}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(withdraw(r.id).url, {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Withdraw
                            </Button>
                        </div>
                    ))}
                </section>
            )}

            <section
                className="cf-card cf-stack vq-card"
                aria-labelledby="vq-rs"
            >
                <h2 id="vq-rs" className="cf-dot-title t-title">
                    Recently shared
                </h2>
                {inbox.recent.map((s) => (
                    <div key={s.id} className="cf-inset vq-recent">
                        <span className="t-label-strong">{s.target}</span>
                        <span className="t-body t-muted">to {s.who}</span>
                        <span>
                            <LevelPill level={s.level} />
                        </span>
                        <span className="t-caption t-muted vq-recent-when">
                            {s.at ? formatDateIn(s.at, timeZone) : ''}
                        </span>
                    </div>
                ))}
                {inbox.recent.length === 0 && (
                    <p className="t-caption t-muted m-0">
                        Nothing shared yet. Share an item from its drawer in the
                        vault.
                    </p>
                )}
            </section>

            <DenyModal row={denying} onClose={() => setDenying(null)} />
        </div>
    );
}

function DenyModal({
    row,
    onClose,
}: {
    row: VaultAccessRequestRow | null;
    onClose: () => void;
}) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (row === null) {
            return;
        }

        router.post(
            deny(row.id).url,
            { note },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setNote('');
                    onClose();
                },
            },
        );
    };

    return (
        <Modal
            open={row !== null}
            onOpenChange={(open) => !open && onClose()}
            size="sm"
            icon={Inbox}
            title={row ? `Deny ${row.name}’s request?` : 'Deny request'}
            subtitle={
                row
                    ? `${LEVEL_LABEL[row.level]} access to ${row.target}`
                    : undefined
            }
            onSubmit={(e) => {
                e.preventDefault();
                submit();
            }}
            footer={
                <>
                    <Button type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="danger" loading={busy}>
                        Deny request
                    </Button>
                </>
            }
        >
            <TextInput
                label="Note for them (optional)"
                value={note}
                maxLength={200}
                placeholder="Use the test keys instead"
                onChange={(e) => setNote(e.currentTarget.value)}
            />
        </Modal>
    );
}
