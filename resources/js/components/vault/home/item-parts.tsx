import { router } from '@inertiajs/react';
import {
    Copy,
    Download,
    Eye,
    EyeOff,
    Globe,
    History,
    KeyRound,
    Paperclip,
    Pencil,
    RefreshCw,
    Trash2,
    UserPlus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button, ButtonLink } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { formatFileSize } from '@/components/ui/file-drop.utils';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { VaultSecretView } from '@/components/ui/password-field';
import { toast } from '@/components/ui/toast';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { copyPlain } from '@/components/vault/home/clipboard';
import { HistoryModal } from '@/components/vault/home/history-modal';
import { StepUpForm } from '@/components/vault/home/step-up-form';
import type { ItemSecrets } from '@/components/vault/home/use-item-secrets';
import { LevelPill } from '@/components/vault/home/vault-bits';
import { useVaultSession } from '@/components/vault/home/vault-session';
import { RequestAccessModal } from '@/components/vault/share/request-access-modal';
import {
    detailRows,
    displayUrl,
    eventMeta,
    formatDateIn,
    LEVEL_LABEL,
    safeHref,
} from '@/components/vault/home/vault-home.utils';
import type { DetailRow } from '@/components/vault/home/vault-home.utils';
import { cn } from '@/lib/utils';
import { destroy, edit } from '@/routes/vault/items';
import type { VaultItemDetail, VaultPerson } from '@/types/vault';

const MASK = '••••••••••••••';

export const partnerOf = (person: VaultPerson): PartnerColour | undefined =>
    person.colour !== null && person.colour >= 1 && person.colour <= 6
        ? (person.colour as PartnerColour)
        : undefined;

/** Copy a non-secret value straight from the page (no server call, nothing to clear). */
export async function copyValue(value: string, label: string): Promise<void> {
    if (await copyPlain(value)) {
        toast.success(`${label} copied`);
    } else {
        toast.error('Your browser blocked the clipboard.');
    }
}

export const rowsOf = (item: VaultItemDetail): DetailRow[] => detailRows(item);

/** "Last changed 28 Sep 2026" beside the main secret's label. */
export function lastChanged(
    item: VaultItemDetail,
    timeZone: string,
): string | undefined {
    return item.rotatedAt
        ? `Last changed ${formatDateIn(item.rotatedAt, timeZone)}`
        : undefined;
}

/* ------------------------------------------------------------- field rows */

function PlainRow({ row }: { row: DetailRow }) {
    return (
        <div className="cf-field">
            <span className="cf-label vh-label">{row.label}</span>
            <div
                className={cn(
                    'cf-inwrap vh-value',
                    row.multiline && 'vh-value--multi',
                )}
            >
                <span className="t-mono vh-value-text">{row.value}</span>
                {row.copyable && row.value !== null && (
                    <IconButton
                        icon={Copy}
                        size="sm"
                        variant="bare"
                        iconSize={16}
                        aria-label={`Copy ${row.label.toLocaleLowerCase('en-IN')}`}
                        onClick={() =>
                            void copyValue(row.value ?? '', row.label)
                        }
                    />
                )}
            </div>
        </div>
    );
}

function MaskedRow({
    label,
    meta,
    text,
}: {
    label: string;
    meta?: string;
    text: 'mask' | 'not-set';
}) {
    return (
        <div className="cf-field">
            <span className="cf-label-row vh-label-row">
                <span className="cf-label vh-label">{label}</span>
                {meta && <span className="cf-help">{meta}</span>}
            </span>
            <div className="cf-inwrap is-readonly vh-value">
                {text === 'mask' ? (
                    <span className="t-mono vh-mask">
                        <span aria-hidden="true">{MASK}</span>
                        <span className="cf-sr">Hidden</span>
                    </span>
                ) : (
                    <span className="t-body t-muted">Not set</span>
                )}
            </div>
        </div>
    );
}

export function StepUpFor({
    secrets,
    target,
    action,
}: {
    secrets: ItemSecrets;
    target: string;
    action?: string;
}) {
    if (secrets.stepUpTarget !== target) {
        return null;
    }

    return (
        <StepUpForm
            action={action}
            onVerify={secrets.verify}
            onCancel={secrets.cancelStepUp}
        />
    );
}

function SecretRow({
    row,
    secrets,
    canReveal,
    meta,
}: {
    row: DetailRow;
    secrets: ItemSecrets;
    canReveal: boolean;
    meta?: string;
}) {
    if (!row.set) {
        return <MaskedRow label={row.label} text="not-set" />;
    }

    if (!canReveal) {
        return <MaskedRow label={row.label} meta={meta} text="mask" />;
    }

    const shown = secrets.shown(row.key);
    const lower = row.label.toLocaleLowerCase('en-IN');

    return (
        <div className="vh-secret">
            <VaultSecretView
                label={row.label}
                meta={meta}
                state={
                    secrets.busy === row.key
                        ? 'verifying'
                        : shown
                          ? 'shown'
                          : 'masked'
                }
                secret={shown?.value ?? null}
                secondsLeft={shown?.secondsLeft}
                copyLabel={`Copy ${lower}`}
                onReveal={() => secrets.reveal(row.key)}
                onHide={() => secrets.hide(row.key)}
                onCopy={() => secrets.copy(row.key)}
            />
            <StepUpFor secrets={secrets} target={row.key} />
        </div>
    );
}

/** Template and custom fields: plain values with Copy, secrets masked with Reveal / Copy. */
export function FieldRows({
    item,
    secrets,
    timeZone,
}: {
    item: VaultItemDetail;
    secrets: ItemSecrets;
    timeZone: string;
}) {
    const rows = rowsOf(item);

    if (rows.length === 0) {
        return null;
    }

    return (
        <>
            {rows.map((row) =>
                row.secret ? (
                    <SecretRow
                        key={row.key}
                        row={row}
                        secrets={secrets}
                        canReveal={item.abilities.reveal}
                        meta={
                            row.primary
                                ? lastChanged(item, timeZone)
                                : undefined
                        }
                    />
                ) : (
                    <PlainRow key={row.key} row={row} />
                ),
            )}
        </>
    );
}

/* ------------------------------------------------------------ the rest */

export function LoginLink({ url }: { url: string | null }) {
    const href = url ? safeHref(url) : null;

    if (url === null || href === null) {
        return null;
    }

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="vh-login"
        >
            <span className="cf-row" style={{ minWidth: 0 }}>
                <Icon icon={Globe} size={16} />
                Open login page
            </span>
            <span className="t-money-sm t-muted vh-ellipsis">
                {displayUrl(url)}
            </span>
        </a>
    );
}

export function NotesBlock({
    item,
    secrets,
}: {
    item: VaultItemDetail;
    secrets: ItemSecrets;
}) {
    if (!item.notesSet) {
        return null;
    }

    const shown = secrets.shown('notes');

    return (
        <div className="cf-field">
            <span className="cf-label-row vh-label-row">
                <span className="cf-label vh-label">Notes</span>
                {shown && (
                    <span className="cf-help">
                        Hides in {shown.secondsLeft} s
                    </span>
                )}
            </span>
            {shown ? (
                <div className="vh-notes">
                    <p className="m-0">{shown.value}</p>
                    <Button
                        size="sm"
                        icon={EyeOff}
                        onClick={() => secrets.hide('notes')}
                    >
                        Hide notes
                    </Button>
                </div>
            ) : item.abilities.reveal ? (
                <div className="vh-notes vh-notes--masked">
                    <span className="t-body t-muted">
                        Notes are encrypted like the password.
                    </span>
                    <Button
                        size="sm"
                        icon={Eye}
                        loading={secrets.busy === 'notes'}
                        onClick={() => secrets.reveal('notes')}
                    >
                        Show notes
                    </Button>
                </div>
            ) : (
                <div className="vh-notes vh-notes--masked">
                    <span className="t-body t-muted">Notes are hidden.</span>
                </div>
            )}
            <StepUpFor
                secrets={secrets}
                target="notes"
                action="show the notes"
            />
        </div>
    );
}

export function AttachmentsBlock({
    item,
    secrets,
}: {
    item: VaultItemDetail;
    secrets: ItemSecrets;
}) {
    if (item.attachments.length === 0) {
        return null;
    }

    return (
        <div className="cf-field">
            <span className="cf-label vh-label">Attachments</span>
            <ul className="vh-files">
                {item.attachments.map((file) => (
                    <li key={file.id}>
                        <div className="vh-file">
                            <Icon icon={Paperclip} size={16} />
                            <span className="vh-file-text">
                                <span className="t-body vh-ellipsis">
                                    {file.name}
                                </span>
                                <span className="t-caption t-muted">
                                    {formatFileSize(file.size)}
                                </span>
                            </span>
                            {item.abilities.reveal && (
                                <IconButton
                                    icon={Download}
                                    size="sm"
                                    variant="bare"
                                    iconSize={16}
                                    loading={secrets.busy === `att:${file.id}`}
                                    aria-label={`Download ${file.name}`}
                                    onClick={() => secrets.download(file)}
                                />
                            )}
                        </div>
                        <StepUpFor
                            secrets={secrets}
                            target={`att:${file.id}`}
                            action="download it"
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function AccessBlock({
    item,
    focus,
}: {
    item: VaultItemDetail;
    /** `?share=1`: bring this section into view and open the share drawer. */
    focus: boolean;
}) {
    const ref = useRef<HTMLElement>(null);
    const reduced = useReducedMotion();
    const { share, timeZone } = useVaultSession();
    const [asking, setAsking] = useState(false);
    const canShare = item.abilities.share;

    useEffect(() => {
        if (focus) {
            ref.current?.scrollIntoView({
                block: 'nearest',
                behavior: reduced ? 'auto' : 'smooth',
            });

            if (canShare) {
                share({ kind: 'item', id: item.id, name: item.name });
            }
        }
    }, [focus, reduced, canShare, share, item.id, item.name]);

    return (
        <section ref={ref} className="vh-block" aria-labelledby="vh-access-t">
            <div className="cf-kv vh-block-head">
                <h3 className="cf-label vh-label m-0" id="vh-access-t">
                    Who has access
                </h3>
                {canShare && (
                    <Button
                        size="sm"
                        icon={UserPlus}
                        onClick={() =>
                            share({
                                kind: 'item',
                                id: item.id,
                                name: item.name,
                            })
                        }
                    >
                        Share
                    </Button>
                )}
                {!canShare &&
                    item.canAsk &&
                    item.ceiling &&
                    !item.pendingRequest && (
                        <Button
                            size="sm"
                            icon={KeyRound}
                            onClick={() => setAsking(true)}
                        >
                            Ask for access
                        </Button>
                    )}
            </div>
            <ul className="vh-people">
                {item.people.map((person) => (
                    <li key={person.id}>
                        <Avatar
                            name={person.name}
                            size="sm"
                            partner={partnerOf(person)}
                            label={false}
                        />
                        <span className="vh-person">
                            {person.name}
                            {person.you && ' (you)'}
                        </span>
                        <LevelPill level={person.level} />
                    </li>
                ))}
            </ul>
            {item.pendingRequest && (
                <p className="t-caption t-muted m-0">
                    You asked for {LEVEL_LABEL[item.pendingRequest.level]}{' '}
                    access
                    {item.pendingRequest.createdAt
                        ? ` on ${formatDateIn(item.pendingRequest.createdAt, timeZone)}`
                        : ''}
                    . A Manager will decide.
                </p>
            )}
            {item.ceiling && item.canAsk && (
                <RequestAccessModal
                    open={asking}
                    onOpenChange={setAsking}
                    target={{
                        kind: 'item',
                        id: item.id,
                        name: item.name,
                        level: item.level,
                    }}
                    ceiling={item.ceiling}
                />
            )}
        </section>
    );
}

export function ActivityBlock({
    item,
    timeZone,
}: {
    item: VaultItemDetail;
    timeZone: string;
}) {
    if (item.activity.length === 0) {
        return null;
    }

    return (
        <section className="vh-block" aria-labelledby="vh-activity-t">
            <h3 className="cf-label vh-label m-0" id="vh-activity-t">
                Activity
            </h3>
            <ol className="vh-activity">
                {item.activity.map((event) => (
                    <li key={event.id}>
                        <span
                            className={cn(
                                'vh-dot',
                                event.strong && 'vh-dot--strong',
                            )}
                            aria-hidden="true"
                        />
                        <span className="cf-stack" style={{ gap: 2 }}>
                            <span className="t-body">{event.text}</span>
                            <span className="t-caption t-muted">
                                {eventMeta(event, timeZone)}
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

/** Rotate, History, Edit and Delete (Vault.dc.html drawer footer). */
export function ItemActions({
    item,
    onDeleted,
}: {
    item: VaultItemDetail;
    onDeleted: () => void;
}) {
    const [history, setHistory] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [busy, setBusy] = useState(false);

    const remove = () =>
        router.delete(destroy(item.id).url, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setDeleting(false);
                onDeleted();
            },
        });

    return (
        <div className="vh-actions">
            {item.abilities.edit && (
                <ButtonLink
                    href={edit(item.id, { query: { rotate: 1 } }).url}
                    icon={RefreshCw}
                    className="vh-action"
                >
                    Rotate
                </ButtonLink>
            )}
            <Button
                icon={History}
                className="vh-action"
                onClick={() => setHistory(true)}
            >
                History
            </Button>
            {item.abilities.edit && (
                <ButtonLink
                    href={edit(item.id).url}
                    variant="primary"
                    icon={Pencil}
                    className="vh-action"
                >
                    Edit
                </ButtonLink>
            )}
            {item.abilities.delete && (
                <IconButton
                    icon={Trash2}
                    variant="neg"
                    aria-label={`Delete ${item.name}`}
                    onClick={() => setDeleting(true)}
                />
            )}

            <HistoryModal
                item={item}
                open={history}
                onOpenChange={setHistory}
            />

            {item.abilities.delete && (
                <ConfirmDialog
                    open={deleting}
                    onOpenChange={setDeleting}
                    onConfirm={remove}
                    loading={busy}
                    icon={Trash2}
                    title={`Delete ${item.name}?`}
                    description="People lose access straight away. Its history stays in the audit log."
                    confirmLabel="Delete item"
                />
            )}
        </div>
    );
}
