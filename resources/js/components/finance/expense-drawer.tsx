import {
    Check,
    Download,
    ExternalLink,
    Pencil,
    Receipt,
    RotateCw,
    X,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { Icon } from '@/components/ui/icon';
import { StatusPill } from '@/components/ui/status-pill';
import { formatDate, formatInr } from '@/lib/format';
import type { ExpenseRow } from '@/types/finance';

export type ExpenseDetail = {
    id: number;
    journalEntryId: number | null;
    salaryMonth: string | null;
    history: {
        id: number;
        event: string;
        by: string | null;
        at: string | null;
        reason: string | null;
    }[];
};

type Props = {
    expense: ExpenseRow | null;
    detail: ExpenseDetail | null;
    onClose: () => void;
    onApprove: (e: ExpenseRow) => void;
    onReject: (e: ExpenseRow) => void;
    onEdit: (e: ExpenseRow) => void;
};

const EVENT: Record<string, string> = {
    submitted: 'Submitted',
    edited: 'Edited',
    approved: 'Approved',
    rejected: 'Rejected',
};

const dateTime = (iso: string) =>
    new Date(iso).toLocaleString('en-IN', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/**
 * Receipt drawer (Expenses + Drawer, DESIGN_MAP): the expense's figure and status, who
 * paid and from where, the receipt (image or PDF preview, open and download), the
 * approval history, and Approve / Reject / Edit when allowed.
 */
export function ExpenseDrawer({
    expense,
    detail,
    onClose,
    onApprove,
    onReject,
    onEdit,
}: Props) {
    const e = expense;
    const isImage = e?.receipt ? /\.(png|jpe?g)$/i.test(e.receipt.name) : false;

    return (
        <Drawer
            open={e !== null}
            onOpenChange={(o) => !o && onClose()}
            title={e?.description ?? ''}
            subtitle={
                e ? `${e.categoryName} · ${formatDate(e.date)}` : undefined
            }
            closeLabel="Close receipt"
            media={
                <span
                    className="cf-avatar cf-avatar--lg cf-avatar--tile"
                    style={{
                        background: 'var(--on-graphite)',
                        color: 'var(--graphite)',
                    }}
                    aria-hidden="true"
                >
                    <Icon icon={e?.auto ? RotateCw : Receipt} size={20} />
                </span>
            }
            figure={
                e && (
                    <>
                        <StatusPill status={e.status} />
                        <span
                            className="t-money"
                            style={{ fontSize: 22, lineHeight: '26px' }}
                        >
                            {formatInr(e.amountPaise)}
                        </span>
                    </>
                )
            }
        >
            {e && (
                <>
                    <dl className="cf-inset ex-facts">
                        <div className="cf-kv t-body">
                            <dt className="t-muted">Submitted by</dt>
                            <dd>
                                {e.auto
                                    ? 'Payroll (from timesheets)'
                                    : (e.submittedBy?.name ?? '—')}
                            </dd>
                        </div>
                        {e.decidedBy && e.decidedAt && (
                            <div className="cf-kv t-body">
                                <dt className="t-muted">
                                    {e.status === 'rejected'
                                        ? 'Rejected by'
                                        : 'Approved by'}
                                </dt>
                                <dd>
                                    {e.decidedBy} ·{' '}
                                    {formatDate(e.decidedAt.slice(0, 10))}
                                </dd>
                            </div>
                        )}
                        {e.projectName && (
                            <div className="cf-kv t-body">
                                <dt className="t-muted">
                                    {e.billable ? 'Bill to client' : 'Project'}
                                </dt>
                                <dd>{e.projectName}</dd>
                            </div>
                        )}
                        {e.notes && (
                            <div className="cf-kv t-body">
                                <dt className="t-muted">Notes</dt>
                                <dd>{e.notes}</dd>
                            </div>
                        )}
                        {detail?.journalEntryId && (
                            <div className="cf-kv t-body">
                                <dt className="t-muted">Journal entry</dt>
                                <dd className="t-money-sm">
                                    #{detail.journalEntryId}
                                </dd>
                            </div>
                        )}
                    </dl>

                    {e.status === 'rejected' && e.rejectReason && (
                        <div className="cf-alert cf-alert--neg cf-alert--slim">
                            <span className="t-caption">
                                Rejected: {e.rejectReason}
                            </span>
                        </div>
                    )}

                    <section className="cf-stack gap-2" aria-label="Paid by">
                        <h3 className="cf-dot-title t-title m-0">Paid by</h3>
                        {e.payers.map((p, i) => (
                            <div key={i} className="cf-inset cf-kv t-body">
                                <span className="cf-stack">
                                    <span className="font-medium">
                                        {p.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {p.fromLabel}
                                    </span>
                                </span>
                                <span className="t-money">
                                    {formatInr(p.amountPaise)}
                                </span>
                            </div>
                        ))}
                    </section>

                    <section className="cf-stack gap-2" aria-label="Receipt">
                        <h3 className="cf-dot-title t-title m-0">Receipt</h3>
                        {e.receipt ? (
                            <>
                                {isImage ? (
                                    <img
                                        src={e.receipt.url}
                                        alt={`Receipt for ${e.description}`}
                                        className="ex-receipt"
                                    />
                                ) : (
                                    <object
                                        data={e.receipt.url}
                                        type="application/pdf"
                                        className="ex-receipt ex-receipt--pdf"
                                        aria-label={`Receipt for ${e.description}`}
                                    >
                                        <span className="t-caption t-muted">
                                            The preview isn’t available here.
                                            Open the file instead.
                                        </span>
                                    </object>
                                )}
                                <div className="cf-actions">
                                    <a
                                        className="cf-btn cf-btn--secondary cf-btn--sm"
                                        href={e.receipt.url}
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <Icon icon={ExternalLink} size={14} />
                                        Open
                                    </a>
                                    <a
                                        className="cf-btn cf-btn--secondary cf-btn--sm"
                                        href={`${e.receipt.url}?download=1`}
                                    >
                                        <Icon icon={Download} size={14} />
                                        Download
                                    </a>
                                </div>
                            </>
                        ) : (
                            <p className="t-caption t-muted m-0">
                                {e.auto
                                    ? 'Salary comes from timesheets, so there’s no receipt.'
                                    : 'No receipt attached.'}
                            </p>
                        )}
                    </section>

                    {detail && detail.history.length > 0 && (
                        <section
                            className="cf-stack gap-2"
                            aria-label="History"
                        >
                            <h3 className="cf-dot-title t-title m-0">
                                History
                            </h3>
                            <ol className="ex-history">
                                {detail.history.map((h) => (
                                    <li key={h.id} className="t-body">
                                        <span className="font-medium">
                                            {EVENT[h.event] ?? h.event}
                                        </span>
                                        {h.by ? ` by ${h.by}` : ''}
                                        {h.at && (
                                            <span className="t-caption t-muted">
                                                {' '}
                                                · {dateTime(h.at)}
                                            </span>
                                        )}
                                        {h.reason && (
                                            <span className="t-caption t-muted cf-block">
                                                “{h.reason}”
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}

                    {(e.can.decide || e.can.edit) && (
                        <div className="cf-actions">
                            {e.can.decide && (
                                <>
                                    <Button
                                        variant="primary"
                                        disc={Check}
                                        className="flex-1"
                                        onClick={() => onApprove(e)}
                                    >
                                        Approve
                                    </Button>
                                    <Button
                                        icon={X}
                                        onClick={() => onReject(e)}
                                    >
                                        Reject
                                    </Button>
                                </>
                            )}
                            {e.can.edit && (
                                <Button icon={Pencil} onClick={() => onEdit(e)}>
                                    Edit
                                </Button>
                            )}
                        </div>
                    )}
                </>
            )}
        </Drawer>
    );
}
