import { Head, router } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    Download,
    FileText,
    ShieldAlert,
    Table2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button, buttonClass } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-picker';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { FilterChip } from '@/components/ui/filter-chips';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Pagination } from '@/components/ui/pagination';
import { SearchField } from '@/components/ui/search-field';
import { Select } from '@/components/ui/select';
import { StatusPill } from '@/components/ui/status-pill';
import type { StatusTone } from '@/components/ui/status-pill';
import { TableWrap } from '@/components/ui/table-wrap';
import { formatIndianNumber } from '@/lib/format';
import {
    exportMethod as auditExport,
    index as auditIndex,
    verify as auditVerify,
} from '@/routes/audit';
import type { AuditFilters, AuditRow, AuditTrailProps } from '@/types/audit';

const TONE: Record<string, StatusTone> = {
    created: 'graphite',
    updated: 'info',
    deleted: 'neg',
    restored: 'graphite',
    posted: 'lime',
    reversed: 'warn',
    approved: 'pos',
    rejected: 'neg',
    reopened: 'warn',
    closed: 'graphite',
    exported: 'warn',
    started: 'outline',
};

/** Query string for the filters, without the page when it's the first. */
function queryOf(f: AuditFilters): Record<string, string | number> {
    const q: Record<string, string | number> = { from: f.from, to: f.to };

    if (f.user !== null) {
        q.user = f.user;
    }

    if (f.action !== null) {
        q.action = f.action;
    }

    if (f.type !== null) {
        q.type = f.type;
    }

    if (f.q !== '') {
        q.q = f.q;
    }

    if (f.page > 1) {
        q.page = f.page;
    }

    return q;
}

/**
 * The audit trail (D153, Companies (Accounts) Rules 3(1)): every change to the books, with
 * who, when, from where and each field before → after. Append-only and hash-chained; the
 * banner says whether the chain is intact. No design board covers it (v2), so it follows
 * the vault audit log's layout.
 */
export default function AuditTrailPage(props: AuditTrailProps) {
    const { filters, chain } = props;
    const [verifying, setVerifying] = useState(false);
    const go = (next: Partial<AuditFilters>) =>
        router.get(
            auditIndex().url,
            queryOf({ ...filters, page: 1, ...next }),
            { preserveScroll: true, preserveState: true, replace: true },
        );
    const exportUrl = (format: 'csv' | 'pdf') =>
        auditExport({
            query: { ...queryOf({ ...filters, page: 1 }), format },
        }).url;

    return (
        <>
            <Head title="Audit trail" />
            <PageHeader
                kicker="Statements"
                title="Audit trail"
                crumbs={[{ title: 'Statements' }, { title: 'Audit trail' }]}
                actions={
                    <DropdownMenu
                        aria-label="Export"
                        align="end"
                        trigger={
                            <button
                                type="button"
                                className={buttonClass({
                                    variant: 'secondary',
                                    size: 'lg',
                                })}
                            >
                                <Icon
                                    icon={Download}
                                    size={18}
                                    strokeWidth={1.75}
                                />
                                Export
                            </button>
                        }
                        entries={[
                            {
                                label: 'CSV',
                                icon: Table2,
                                onSelect: () =>
                                    window.location.assign(exportUrl('csv')),
                            },
                            {
                                label: 'PDF',
                                icon: FileText,
                                onSelect: () =>
                                    window.location.assign(exportUrl('pdf')),
                            },
                        ]}
                    />
                }
            />

            {chain.ok ? (
                <AlertBanner
                    variant="pos"
                    icon={Check}
                    iconStrokeWidth={2}
                    title="Chain verified ✓"
                    aria-label="Audit trail check"
                    action={
                        <Button
                            loading={verifying}
                            onClick={() =>
                                router.post(
                                    auditVerify().url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setVerifying(true),
                                        onFinish: () => setVerifying(false),
                                    },
                                )
                            }
                        >
                            {verifying ? 'Verifying' : 'Verify now'}
                        </Button>
                    }
                >
                    All {formatIndianNumber(chain.events)} events are intact.
                    Nothing in the trail can be edited or deleted, and each
                    event is sealed to the one before it.
                </AlertBanner>
            ) : (
                <AlertBanner
                    variant="neg"
                    icon={ShieldAlert}
                    role="alert"
                    title="The audit trail was changed outside Coffer"
                    aria-label="Audit trail check"
                >
                    The check found that {chain.problem} Coffer never edits the
                    trail, so this needs looking into. Your Admins have been
                    told.
                </AlertBanner>
            )}

            <AuditBody {...props} go={go} />
        </>
    );
}

function AuditBody({
    filters,
    groups,
    types,
    people,
    counts,
    rows,
    total,
    page,
    pages,
    perPage,
    go,
}: AuditTrailProps & { go: (next: Partial<AuditFilters>) => void }) {
    const [q, setQ] = useState(filters.q);
    const first = useRef(true);
    const [open, setOpen] = useState<number | null>(null);

    // Search as you type, a moment after the last key.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const t = window.setTimeout(() => {
            if (q.trim() !== filters.q) {
                go({ q: q.trim() });
            }
        }, 300);

        return () => window.clearTimeout(t);
    }, [q, filters.q, go]);

    const start = (page - 1) * perPage + 1;
    const end = Math.min(page * perPage, total);

    return (
        <section className="cf-card cf-stack au-card" aria-labelledby="au-h">
            <div className="cf-kv au-head">
                <h2 id="au-h" className="cf-dot-title t-title">
                    Changes
                </h2>
                <div className="cf-row au-filters">
                    <SearchField
                        aria-label="Search records, vouchers or IPs"
                        placeholder="Record, voucher or IP"
                        value={q}
                        onValueChange={setQ}
                        wrapClassName="au-search"
                    />
                    <Select
                        pill
                        aria-label="Person"
                        value={
                            filters.user === null ? '' : String(filters.user)
                        }
                        onChange={(e) =>
                            go({
                                user:
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                            })
                        }
                        options={[
                            { value: '', label: 'Everyone' },
                            ...people.map((p) => ({
                                value: String(p.id),
                                label: p.name,
                            })),
                        ]}
                    />
                    <Select
                        pill
                        aria-label="Record type"
                        value={filters.type ?? ''}
                        onChange={(e) =>
                            go({
                                type:
                                    e.target.value === ''
                                        ? null
                                        : e.target.value,
                            })
                        }
                        options={[
                            { value: '', label: 'All records' },
                            ...types,
                        ]}
                    />
                </div>
            </div>
            <DateRangePicker
                label="Dates"
                value={{ start: filters.from, end: filters.to }}
                onValueChange={(r) => {
                    if (r.start && r.end) {
                        go({ from: r.start, to: r.end });
                    }
                }}
                fieldClassName="au-dates"
            />
            <div className="cf-row au-chips" role="group" aria-label="Action">
                <FilterChip
                    pressed={filters.action === null}
                    count={counts.all ?? 0}
                    onClick={() => go({ action: null })}
                >
                    All
                </FilterChip>
                {groups.map((g) => (
                    <FilterChip
                        key={g.key}
                        pressed={filters.action === g.key}
                        count={counts[g.key] ?? 0}
                        onClick={() =>
                            go({
                                action: filters.action === g.key ? null : g.key,
                            })
                        }
                    >
                        {g.label}
                    </FilterChip>
                ))}
            </div>

            <TableWrap label="Audit trail">
                <div
                    className="cf-table au-table"
                    role="table"
                    aria-label="Audit trail"
                >
                    <div className="cf-tr cf-thead au-cols" role="row">
                        <span role="columnheader">#</span>
                        <span role="columnheader">Time</span>
                        <span role="columnheader">Person</span>
                        <span role="columnheader">Action</span>
                        <span role="columnheader">Record</span>
                        <span role="columnheader">Voucher</span>
                        <span role="columnheader">From</span>
                        <span role="columnheader">
                            <span className="sr-only">Details</span>
                        </span>
                    </div>
                    {rows.map((r) => (
                        <AuditLine
                            key={r.id}
                            row={r}
                            open={open === r.id}
                            onToggle={() =>
                                setOpen(open === r.id ? null : r.id)
                            }
                        />
                    ))}
                    {rows.length === 0 && (
                        <div className="cf-empty-row t-body t-muted">
                            No changes match these filters.
                        </div>
                    )}
                </div>
            </TableWrap>
            <Pagination
                page={page}
                pageCount={pages}
                onPageChange={(p) => go({ page: p })}
                summary={`${
                    total === 0
                        ? 'No changes'
                        : `Showing ${start}–${end} of ${formatIndianNumber(total)}`
                } · The trail is kept for good and can’t be edited.`}
            />
        </section>
    );
}

function AuditLine({
    row,
    open,
    onToggle,
}: {
    row: AuditRow;
    open: boolean;
    onToggle: () => void;
}) {
    const detailId = `au-detail-${row.id}`;

    return (
        <>
            <div className="cf-tr au-cols" role="row">
                <span role="cell" className="t-money-sm t-muted">
                    {row.seq}
                </span>
                <span role="cell" className="t-body">
                    {row.time}
                </span>
                <span role="cell" className="cf-stack">
                    <span className="t-body">{row.person}</span>
                    {row.impersonator && (
                        <span className="t-caption t-warn">
                            Support: {row.impersonator}
                        </span>
                    )}
                </span>
                <span role="cell">
                    <StatusPill tone={TONE[row.action] ?? 'outline'} size="sm">
                        {row.actionLabel}
                    </StatusPill>
                </span>
                <span role="cell" className="cf-stack au-record">
                    <span className="t-label-strong">{row.label ?? '—'}</span>
                    {row.type && (
                        <span className="t-caption t-muted">{row.type}</span>
                    )}
                </span>
                <span role="cell" className="t-money-sm">
                    {row.voucher ?? '—'}
                </span>
                <span role="cell" className="cf-stack">
                    <span className="t-money-sm t-muted">{row.ip ?? '—'}</span>
                    {row.device && (
                        <span className="t-caption t-muted">{row.device}</span>
                    )}
                </span>
                <span role="cell">
                    {row.changes.length > 0 && (
                        <IconButton
                            icon={ChevronDown}
                            size="sm"
                            className={open ? 'au-chev is-open' : 'au-chev'}
                            aria-expanded={open}
                            aria-controls={detailId}
                            aria-label={
                                open
                                    ? `Hide the changes in event ${row.seq}`
                                    : `Show the changes in event ${row.seq}`
                            }
                            onClick={onToggle}
                        />
                    )}
                </span>
            </div>
            {open && (
                <div id={detailId} className="cf-tr au-detail" role="row">
                    <div role="cell" className="au-detail-cell">
                        <table className="au-diff">
                            <thead>
                                <tr>
                                    <th scope="col">Field</th>
                                    <th scope="col">Before</th>
                                    <th scope="col">After</th>
                                </tr>
                            </thead>
                            <tbody>
                                {row.changes.map((c) => (
                                    <tr key={c.field}>
                                        <th scope="row">{c.field}</th>
                                        {c.changed ? (
                                            <td colSpan={2} className="t-muted">
                                                Changed (encrypted, so the
                                                values aren’t kept here)
                                            </td>
                                        ) : (
                                            <>
                                                <td className="au-val">
                                                    {c.from ?? '—'}
                                                </td>
                                                <td className="au-val">
                                                    {c.to ?? '—'}
                                                </td>
                                            </>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="t-caption t-muted m-0">
                            Event {row.seq} · seal {row.hash}…
                        </p>
                    </div>
                </div>
            )}
        </>
    );
}
