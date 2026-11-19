import { router } from '@inertiajs/react';
import { Archive, ArchiveRestore, Trash2, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { FieldError } from '@/components/ui/field';
import { PasswordField } from '@/components/ui/password-field';
import { formatDate } from '@/lib/format';
import { keep, restore, switchMethod } from '@/routes/companies';
import {
    archive as archiveCompany,
    deleteMethod as deleteCompany,
} from '@/routes/settings/company';

export type LifecycleCompany = {
    id: number;
    name: string;
    workspace: string;
    /** "YYYY-MM-DD" when archived. */
    archivedAt: string | null;
    /** "YYYY-MM-DD" it is erased on, while its deletion can still be cancelled. */
    deletionScheduledFor: string | null;
    /** A member can open an archived company read-only. */
    canOpen: boolean;
};

export type Lifecycle = {
    companyId: number;
    /** Admin of this company or the workspace owner (`company.remove`). */
    canManage: boolean;
    archivedAt: string | null;
    /** The person's last company: deleting it means deleting the workspace. */
    onlyCompany: boolean;
    owner: boolean;
    graceDays: number;
    others: LifecycleCompany[];
};

type Pending = 'archive' | 'delete' | null;

/**
 * Settings › Company › Archive or delete (V4, D139–D141; no design board, so it uses the
 * kit's card, buttons and type-to-confirm dialog like Profile › Your data). Archive takes
 * the company out of the switcher and the plan's count, read-only, restorable; Delete
 * erases it after the grace period and can be cancelled until then. Below, the archived and
 * deleted companies this person can restore or keep.
 */
export function CompanyLifecycleCard({
    companyName,
    lifecycle,
}: {
    companyName: string;
    lifecycle: Lifecycle;
}) {
    const [pending, setPending] = useState<Pending>(null);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const {
        companyId,
        canManage,
        archivedAt,
        onlyCompany,
        owner,
        graceDays,
        others,
    } = lifecycle;

    if (!canManage && others.length === 0) {
        return null;
    }

    const close = () => {
        setPending(null);
        setPassword('');
        setError(undefined);
    };

    const confirm = () => {
        router.post(
            pending === 'archive' ? archiveCompany().url : deleteCompany().url,
            pending === 'archive'
                ? { confirmation: companyName }
                : { confirmation: companyName, password },
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: close,
                onError: (errors) =>
                    setError(
                        errors.password ??
                            errors.confirmation ??
                            errors.company ??
                            Object.values(errors)[0],
                    ),
            },
        );
    };

    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    const onlyHelp = owner
        ? 'It’s your only company. To erase it, delete the workspace in Profile › Your data.'
        : 'It’s your only company, so it can’t be deleted here. The workspace owner can delete the whole workspace.';

    return (
        <section className="cf-card co-card" aria-labelledby="co-life">
            <h2 id="co-life" className="cf-dot-title t-title">
                Archive or delete
            </h2>

            {canManage && (
                <>
                    <div className="cf-kv flex-wrap gap-3">
                        <div className="cf-stack min-w-0 flex-1 gap-1">
                            <span className="t-label-strong">
                                {archivedAt
                                    ? `Archived on ${formatDate(archivedAt)}`
                                    : 'Archive this company'}
                            </span>
                            <span className="cf-help">
                                {archivedAt
                                    ? 'It’s read-only and doesn’t count toward the plan. Restoring it brings it back to the company switcher, if the plan has room.'
                                    : 'It leaves the company switcher and stops counting toward the plan. Everything stays, read-only, and an Admin can restore it.'}
                            </span>
                        </div>
                        {archivedAt ? (
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={ArchiveRestore}
                                onClick={() => post(restore(companyId).url)}
                            >
                                Restore company
                            </Button>
                        ) : (
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={Archive}
                                onClick={() => setPending('archive')}
                            >
                                Archive company
                            </Button>
                        )}
                    </div>

                    <div className="cf-kv flex-wrap gap-3 border-t border-[var(--line)] pt-4">
                        <div className="cf-stack min-w-0 flex-1 gap-1">
                            <span className="t-label-strong">
                                Delete this company
                            </span>
                            <span className="cf-help">
                                {onlyCompany
                                    ? onlyHelp
                                    : `Its books, files and vault are erased in ${graceDays} days; until then it can be kept. The workspace and its other companies stay.`}
                            </span>
                        </div>
                        <Button
                            variant="danger"
                            size="sm"
                            icon={Trash2}
                            disabled={onlyCompany}
                            onClick={() => setPending('delete')}
                        >
                            Delete company
                        </Button>
                    </div>
                </>
            )}

            {others.length > 0 && (
                <div className="cf-stack gap-3 border-t border-[var(--line)] pt-4">
                    <h3 className="t-label-strong m-0">
                        Archived and deleted companies
                    </h3>
                    {others.map((c) => (
                        <div
                            key={c.id}
                            className="cf-inset cf-kv flex-wrap gap-3"
                        >
                            <div className="cf-stack min-w-0 flex-1 gap-1">
                                <span className="t-label-strong">{c.name}</span>
                                <span className="t-caption t-muted">
                                    {c.deletionScheduledFor
                                        ? `Deleted · erased on ${formatDate(c.deletionScheduledFor)}`
                                        : `Archived ${c.archivedAt ? formatDate(c.archivedAt) : ''}`}{' '}
                                    · {c.workspace}
                                </span>
                            </div>
                            <div className="cf-row flex-wrap gap-2">
                                {c.deletionScheduledFor ? (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        icon={Undo2}
                                        onClick={() => post(keep(c.id).url)}
                                    >
                                        Keep company
                                    </Button>
                                ) : (
                                    <>
                                        {c.canOpen && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    post(switchMethod(c.id).url)
                                                }
                                            >
                                                Open
                                            </Button>
                                        )}
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            icon={ArchiveRestore}
                                            onClick={() =>
                                                post(restore(c.id).url)
                                            }
                                        >
                                            Restore
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && close()}
                icon={pending === 'archive' ? Archive : Trash2}
                title={
                    pending === 'archive'
                        ? `Archive ${companyName}?`
                        : `Delete ${companyName}?`
                }
                description={
                    pending === 'archive'
                        ? 'It leaves the company switcher and frees its place on the plan. Its books, files and vault stay, read-only, until an Admin restores it.'
                        : `Everyone loses access now. Its books, files and vault are erased in ${graceDays} days; until then an Admin or the workspace owner can keep it from Settings › Company.`
                }
                confirmLabel={
                    pending === 'archive' ? 'Archive company' : 'Delete company'
                }
                confirmPhrase={companyName}
                loading={busy}
                onConfirm={confirm}
            >
                {pending === 'delete' ? (
                    <PasswordField
                        id="co-life-pw"
                        label="Your password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        error={error}
                    />
                ) : (
                    error && <FieldError>{error}</FieldError>
                )}
            </ConfirmDialog>
        </section>
    );
}
