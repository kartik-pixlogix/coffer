import { router } from '@inertiajs/react';
import {
    Check,
    Clock,
    Download,
    FileArchive,
    Trash2,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { Button, buttonClass } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { PasswordField } from '@/components/ui/password-field';
import { StatusPill } from '@/components/ui/status-pill';
import { formatDate } from '@/lib/format';
import {
    destroy as deleteAccount,
    restore as restoreAccount,
} from '@/routes/privacy/account';
import { store as storeExport } from '@/routes/privacy/exports';
import {
    destroy as deleteWorkspace,
    restore as restoreWorkspace,
} from '@/routes/privacy/workspaces';
import type { PrivacyProps } from '@/types/privacy';

type Pending =
    | { kind: 'account' }
    | { kind: 'workspace'; id: number; name: string }
    | null;

function ExportStatus({ status }: { status: 'queued' | 'ready' | 'failed' }) {
    if (status === 'ready') {
        return (
            <StatusPill size="sm" tone="pos" icon={Check}>
                Ready
            </StatusPill>
        );
    }

    if (status === 'failed') {
        return (
            <StatusPill size="sm" tone="neg" icon={X}>
                Failed
            </StatusPill>
        );
    }

    return (
        <StatusPill size="sm" tone="warn" icon={Clock}>
            Preparing
        </StatusPill>
    );
}

/**
 * Account › Your data (spec §23 "DPDPA-ready", D98): download a copy of your personal data,
 * delete your account after a grace period, and, for workspace owners, export or delete a
 * whole workspace. Every deletion asks for the password and a typed confirmation.
 */
export function PrivacyCard({ privacy }: { privacy: PrivacyProps }) {
    const [pending, setPending] = useState<Pending>(null);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);

    const close = () => {
        setPending(null);
        setPassword('');
        setError(undefined);
    };

    const requestExport = (workspaceId?: number) =>
        router.post(
            storeExport().url,
            workspaceId === undefined
                ? { kind: 'account' }
                : { kind: 'workspace', workspace_id: workspaceId },
            { preserveScroll: true },
        );

    const confirmDelete = () => {
        if (pending === null) {
            return;
        }

        const url =
            pending.kind === 'account'
                ? deleteAccount().url
                : deleteWorkspace(pending.id).url;

        router.post(
            url,
            {
                password,
                confirmation:
                    pending.kind === 'account' ? 'DELETE' : pending.name,
            },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: close,
                onError: (errors) =>
                    setError(
                        errors.password ??
                            errors.confirmation ??
                            errors.account,
                    ),
            },
        );
    };

    return (
        <section className="cf-card flex flex-col gap-3" aria-labelledby="pv-h">
            <div className="cf-kv">
                <h2 id="pv-h" className="cf-dot-title t-title">
                    Your data
                </h2>
                <span className="t-caption t-muted">DPDPA</span>
            </div>

            <p className="t-body t-muted m-0">
                Download a copy of your profile, memberships, the records you
                created and your notifications. Links work for{' '}
                {privacy.exportTtlDays} days.
            </p>
            <Button
                variant="secondary"
                size="sm"
                icon={FileArchive}
                className="self-start"
                onClick={() => requestExport()}
            >
                Export my data
            </Button>

            {privacy.exports.length > 0 && (
                <ul className="cf-stack m-0 list-none gap-2 p-0">
                    {privacy.exports.map((e) => (
                        <li key={e.id} className="sess">
                            <span
                                className="cf-avatar cf-avatar--tile cf-avatar--graphite"
                                aria-hidden="true"
                            >
                                <Icon icon={FileArchive} size={18} />
                            </span>
                            <span className="cf-stack min-w-0">
                                <span className="cf-row flex-wrap gap-1.5">
                                    <span className="t-label-strong">
                                        {e.label}
                                    </span>
                                    <ExportStatus status={e.status} />
                                </span>
                                <span className="t-caption t-muted">
                                    Requested {formatDate(e.createdAt)}
                                    {e.expiresAt
                                        ? ` · link ends ${formatDate(e.expiresAt)}`
                                        : ''}
                                </span>
                            </span>
                            {e.url !== null && (
                                <a
                                    href={e.url}
                                    className={buttonClass({
                                        variant: 'secondary',
                                        size: 'sm',
                                    })}
                                    aria-label={`Download ${e.label}`}
                                >
                                    <Icon icon={Download} size={14} />
                                    Download
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <div className="cf-stack gap-2 border-t border-[var(--line)] pt-3">
                <h3 className="t-label-strong m-0">Delete your account</h3>
                {privacy.deletion ? (
                    <>
                        <p className="t-body m-0" role="status">
                            <Icon icon={TriangleAlert} size={14} /> Your account
                            will be deleted on{' '}
                            {formatDate(privacy.deletion.scheduledFor)}. Until
                            then you can keep it.
                        </p>
                        <Button
                            variant="secondary"
                            size="sm"
                            className="self-start"
                            onClick={() =>
                                router.post(
                                    restoreAccount().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Keep my account
                        </Button>
                    </>
                ) : (
                    <>
                        <p className="t-caption t-muted m-0">
                            After {privacy.graceDays} days your name, email,
                            sign-in and personal settings are erased. Financial
                            records you posted stay in the books under “Deleted
                            user”, as the law requires.
                        </p>
                        <Button
                            variant="danger"
                            size="sm"
                            icon={Trash2}
                            className="self-start"
                            onClick={() => setPending({ kind: 'account' })}
                        >
                            Delete my account
                        </Button>
                    </>
                )}
            </div>

            {privacy.ownedWorkspaces.map((w) => (
                <div
                    key={w.id}
                    className="cf-stack gap-2 border-t border-[var(--line)] pt-3"
                >
                    <h3 className="t-label-strong m-0">Workspace: {w.name}</h3>
                    <span className="t-caption t-muted">
                        You own it · {w.companies}{' '}
                        {w.companies === 1 ? 'company' : 'companies'}
                    </span>
                    {w.deletion ? (
                        <p className="t-body m-0" role="status">
                            <Icon icon={TriangleAlert} size={14} /> Every
                            company in it will be deleted on{' '}
                            {formatDate(w.deletion.scheduledFor)}.
                        </p>
                    ) : null}
                    <div className="cf-row flex-wrap gap-2">
                        <Button
                            variant="secondary"
                            size="sm"
                            icon={FileArchive}
                            onClick={() => requestExport(w.id)}
                        >
                            Export workspace
                        </Button>
                        {w.deletion ? (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        restoreWorkspace(w.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Keep workspace
                            </Button>
                        ) : (
                            <Button
                                variant="danger"
                                size="sm"
                                icon={Trash2}
                                onClick={() =>
                                    setPending({
                                        kind: 'workspace',
                                        id: w.id,
                                        name: w.name,
                                    })
                                }
                            >
                                Delete workspace
                            </Button>
                        )}
                    </div>
                </div>
            ))}

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && close()}
                icon={Trash2}
                title={
                    pending?.kind === 'workspace'
                        ? `Delete ${pending.name}?`
                        : 'Delete your account?'
                }
                description={
                    pending?.kind === 'workspace'
                        ? `Its subscription is cancelled now. Every company, its books, files and vault are erased in ${privacy.graceDays} days; until then you can keep it. Export it first if you need a copy.`
                        : `Your account is erased in ${privacy.graceDays} days; until then you can keep it. Ledger entries you posted stay, under “Deleted user”.`
                }
                confirmLabel={
                    pending?.kind === 'workspace'
                        ? 'Delete workspace'
                        : 'Delete account'
                }
                confirmPhrase={
                    pending?.kind === 'workspace' ? pending.name : 'DELETE'
                }
                loading={busy}
                onConfirm={confirmDelete}
            >
                <PasswordField
                    id="pv-pw"
                    label="Your password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={error}
                />
            </ConfirmDialog>
        </section>
    );
}
