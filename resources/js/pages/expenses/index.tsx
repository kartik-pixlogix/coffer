import { Head, router } from '@inertiajs/react';
import {
    Check,
    Clock,
    Download,
    Landmark,
    Paperclip,
    Pencil,
    Plus,
    Receipt,
    RotateCw,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ExpenseDrawer } from '@/components/finance/expense-drawer';
import type { ExpenseDetail } from '@/components/finance/expense-drawer';
import type { MeProps } from '@/components/finance/expense-form';
import { ExpenseModal } from '@/components/finance/expense-modal';
import { ExpenseSheet } from '@/components/finance/expense-sheet';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import { RejectDialog } from '@/components/finance/reject-dialog';
import { PageHeader } from '@/components/shell/page-header';
import { initialsFrom, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { AvatarStack } from '@/components/ui/avatar-stack';
import { BulkActionBar } from '@/components/ui/bulk-action-bar';
import { Button, buttonClass } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn, SortState } from '@/components/ui/data-table';
import { FilterChip, FilterChipGroup } from '@/components/ui/filter-chips';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Pager, PaginationBar } from '@/components/ui/pagination';
import { SearchField } from '@/components/ui/search-field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { StatusPill } from '@/components/ui/status-pill';
import { Tooltip } from '@/components/ui/tooltip';
import { PHONE_QUERY, useMediaQuery } from '@/hooks/use-media-query';
import {
    formatDate,
    formatIndianNumber,
    formatInr,
    toDate,
} from '@/lib/format';
import { monthLong } from '@/lib/team';
import {
    approve as approveRoute,
    bulk as bulkRoute,
    destroy as deleteRoute,
    exportMethod as exportRoute,
    index as expensesIndex,
    reject as rejectRoute,
} from '@/routes/expenses';
import type {
    ExpenseOptions,
    ExpenseRow,
    ExpenseStatus,
    FiscalYearProps,
    Paginated,
} from '@/types/finance';

type Filters = {
    status?: ExpenseStatus;
    category?: number;
    payer?: string;
    month?: string;
    project?: number;
    q?: string;
    mine?: number;
    sort?: 'amount';
    dir?: 'asc';
};

type Props = {
    company: { name: string; jv: boolean };
    today: string;
    fy: FiscalYearProps;
    filters: Filters;
    kpis: {
        approvedPaise: number;
        approvedCount: number;
        pendingPaise: number;
        pendingCount: number;
    };
    statusCounts: Record<'all' | ExpenseStatus, number>;
    month: { label: string; paise: number; count: number; waiting: number };
    expenses: Paginated<ExpenseRow>;
    detail: ExpenseDetail | null;
    options: ExpenseOptions;
    me: MeProps;
    abilities: {
        create: boolean;
        approve: boolean;
        addCategory: boolean;
        ownOnly: boolean;
    };
};

type Query = Record<string, string | number | null | undefined>;

const dot = (colour: number | null | undefined) =>
    colour ? partnerColourVar(colour as PartnerColour) : 'var(--ink-faint)';

const paidLabel = (e: ExpenseRow) =>
    e.mode === 'company'
        ? 'Company account'
        : e.mode === 'half'
          ? '50/50 split'
          : e.mode === 'custom' && e.payers.length > 1
            ? 'Custom split'
            : (e.payers[0]?.name ?? '');

/**
 * Expenses (Expenses.dc.html, spec §10): KPIs, filters and status chips, the list with
 * Approve / Reject, the receipt drawer, bulk actions and export. Auto rows come from
 * salary runs and can't be edited. At phone width it follows MobileExpenses.dc.html.
 */
export default function Expenses(props: Props) {
    const phone = useMediaQuery(PHONE_QUERY);
    const { filters, fy, expenses, abilities, detail } = props;
    const [modal, setModal] = useState(false);
    const [editing, setEditing] = useState<ExpenseRow | null>(null);
    const [drawerId, setDrawerId] = useState<number | null>(detail?.id ?? null);
    const [rejecting, setRejecting] = useState<ExpenseRow[] | null>(null);
    const [rejectError, setRejectError] = useState<string | undefined>();
    const [removing, setRemoving] = useState<ExpenseRow | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [selected, setSelected] = useState<Set<string>>(new Set());

    const query: Query = { fy: fy.start, ...filters };
    const visit = (next: Query, only?: string[]) =>
        router.get(expensesIndex().url, cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
        });

    const open = (row: ExpenseRow | null) => {
        setEditing(row);
        setDrawerId(null);
        setModal(true);
    };

    const showReceipt = (row: ExpenseRow) => {
        setDrawerId(row.id);
        router.reload({ only: ['detail'], data: { expense: row.id } });
    };

    const approve = (row: ExpenseRow) =>
        router.post(
            approveRoute(row.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(`a${row.id}`),
                onFinish: () => setBusy(null),
            },
        );

    const reject = (reason: string) => {
        if (!rejecting) {
            return;
        }

        const done = {
            preserveScroll: true,
            onStart: () => setBusy('reject'),
            onFinish: () => setBusy(null),
            onSuccess: () => {
                setRejecting(null);
                setSelected(new Set());
            },
            onError: (bag: Record<string, string>) =>
                setRejectError(bag.reason ?? bag.expense),
        };

        if (rejecting.length === 1) {
            router.post(rejectRoute(rejecting[0].id).url, { reason }, done);
        } else {
            router.post(
                bulkRoute().url,
                { action: 'reject', ids: rejecting.map((r) => r.id), reason },
                done,
            );
        }
    };

    const bulkApprove = () =>
        router.post(
            bulkRoute().url,
            { action: 'approve', ids: [...selected].map(Number) },
            {
                preserveScroll: true,
                onStart: () => setBusy('bulk'),
                onFinish: () => setBusy(null),
                onSuccess: () => setSelected(new Set()),
            },
        );

    const drawerRow = expenses.data.find((e) => e.id === drawerId) ?? null;

    const shared = {
        ...props,
        visit,
        onOpen: open,
        onReceipt: showReceipt,
    };

    return (
        <>
            <Head title="Expenses" />
            {phone ? (
                <PhoneExpenses {...shared} />
            ) : (
                <DesktopExpenses
                    {...shared}
                    selected={selected}
                    onSelect={setSelected}
                    busy={busy}
                    onApprove={approve}
                    onReject={(rows) => {
                        setRejectError(undefined);
                        setRejecting(rows);
                    }}
                    onDelete={setRemoving}
                    onBulkApprove={bulkApprove}
                />
            )}

            {abilities.create &&
                (phone ? (
                    <ExpenseSheet
                        open={modal}
                        onOpenChange={setModal}
                        options={props.options}
                        me={props.me}
                        ownOnly={abilities.ownOnly}
                        today={props.today}
                    />
                ) : (
                    <ExpenseModal
                        open={modal}
                        onOpenChange={setModal}
                        expense={editing}
                        options={props.options}
                        me={props.me}
                        company={props.company}
                        abilities={abilities}
                        today={props.today}
                    />
                ))}

            <ExpenseDrawer
                expense={drawerRow}
                detail={detail?.id === drawerId ? detail : null}
                onClose={() => setDrawerId(null)}
                onApprove={approve}
                onReject={(row) => {
                    setRejectError(undefined);
                    setRejecting([row]);
                }}
                onEdit={open}
            />

            <RejectDialog
                open={rejecting !== null}
                onOpenChange={(o) => !o && setRejecting(null)}
                what={
                    rejecting && rejecting.length === 1
                        ? `“${rejecting[0].description}”`
                        : `${rejecting?.length ?? 0} expenses`
                }
                busy={busy === 'reject'}
                error={rejectError}
                onReject={reject}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title="Delete this expense?"
                description={
                    removing
                        ? `${removing.description} · ${formatInr(removing.amountPaise)}. ${removing.status === 'approved' ? 'Its entry is reversed in the books.' : 'Nothing was posted, so the books don’t change.'}`
                        : undefined
                }
                icon={Trash2}
                confirmLabel="Delete expense"
                loading={busy === 'delete'}
                onConfirm={() => {
                    if (!removing) {
                        return;
                    }

                    router.delete(deleteRoute(removing.id).url, {
                        preserveScroll: true,
                        onStart: () => setBusy('delete'),
                        onFinish: () => {
                            setBusy(null);
                            setRemoving(null);
                        },
                    });
                }}
            />
        </>
    );
}

type SharedProps = Props & {
    visit: (next: Query, only?: string[]) => void;
    onOpen: (row: ExpenseRow | null) => void;
    onReceipt: (row: ExpenseRow) => void;
};

/** Months of the fiscal year up to today, for the month filter. */
function fyMonths(fy: FiscalYearProps, today: string) {
    const months: string[] = [];
    const start = toDate(fy.from);
    const last = fy.current ? today.slice(0, 7) : fy.to.slice(0, 7);

    for (let i = 0; i < 12; i++) {
        const d = new Date(start.getFullYear(), start.getMonth() + i, 1);
        const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;

        if (ym > last) {
            break;
        }

        months.push(ym);
    }

    return months.reverse();
}

function SubmittedBy({ e }: { e: ExpenseRow }) {
    if (e.auto) {
        return (
            <Tooltip label="Salary runs post these from timesheets. Change the hours to change them.">
                <span className="cf-row t-body" tabIndex={0}>
                    <span className="ex-auto-ic" aria-hidden="true">
                        <Icon icon={RotateCw} size={14} strokeWidth={1.75} />
                    </span>
                    Payroll
                </span>
            </Tooltip>
        );
    }

    if (!e.submittedBy) {
        return <span className="t-body t-muted">—</span>;
    }

    return (
        <span className="cf-row t-body">
            <span className="cf-avatar cf-avatar--sm" aria-hidden="true">
                {initialsFrom(e.submittedBy.name)}
                <span
                    className="cf-avatar-dot"
                    style={{ background: dot(e.submittedBy.colour) }}
                />
            </span>
            {e.submittedBy.name}
        </span>
    );
}

function PaidBy({ e }: { e: ExpenseRow }) {
    const company = e.mode === 'company';

    return (
        <span className="cf-row t-body">
            {company ? (
                <span className="ex-direct-ic" aria-hidden="true">
                    <Icon icon={Landmark} size={14} strokeWidth={1.75} />
                </span>
            ) : (
                <AvatarStack
                    people={e.payers.map((p) => ({
                        name: p.name,
                        initials: p.type === 'company' ? 'CO' : undefined,
                        partner: (p.colour ?? undefined) as
                            | PartnerColour
                            | undefined,
                    }))}
                    label={`Paid by ${e.payers.map((p) => p.name).join(' and ')}`}
                />
            )}
            <span
                title={e.payers
                    .map(
                        (p) =>
                            `${p.name}: ${formatInr(p.amountPaise)} (${p.fromLabel})`,
                    )
                    .join('\n')}
            >
                {paidLabel(e)}
            </span>
        </span>
    );
}

function DesktopExpenses({
    today,
    fy,
    filters,
    kpis,
    statusCounts,
    expenses,
    options,
    abilities,
    visit,
    onOpen,
    onReceipt,
    selected,
    onSelect,
    busy,
    onApprove,
    onReject,
    onDelete,
    onBulkApprove,
}: SharedProps & {
    selected: Set<string>;
    onSelect: (s: Set<string>) => void;
    busy: string | null;
    onApprove: (e: ExpenseRow) => void;
    onReject: (rows: ExpenseRow[]) => void;
    onDelete: (e: ExpenseRow) => void;
    onBulkApprove: () => void;
}) {
    const [search, setSearch] = useState(filters.q ?? '');
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    const onSearch = (q: string) => {
        setSearch(q);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () => visit({ q: q.trim() || null, page: null }),
            300,
        );
    };

    const total = kpis.approvedPaise + kpis.pendingPaise;
    const where = filters.month ? monthLong(`${filters.month}-01`) : fy.label;
    const filtered =
        filters.status !== undefined ||
        filters.category !== undefined ||
        filters.payer !== undefined ||
        filters.project !== undefined ||
        filters.month !== undefined ||
        filters.q !== undefined;
    const sort: SortState = {
        key: filters.sort === 'amount' ? 'amount' : 'date',
        direction: filters.dir === 'asc' ? 'ascending' : 'descending',
    };
    const selectedRows = expenses.data.filter((e) =>
        selected.has(String(e.id)),
    );
    const exportUrl = exportRoute({
        query: cleanQuery({
            fy: fy.start,
            status: filters.status,
            category: filters.category,
            payer: filters.payer,
            month: filters.month,
            project: filters.project,
            q: filters.q,
            mine: filters.mine,
        }),
    }).url;
    const exportSelected = () => {
        const params = new URLSearchParams();
        selectedRows.forEach((r) => params.append('ids[]', String(r.id)));
        window.location.href = `${exportRoute().url}?${params.toString()}`;
    };

    const columns: DataTableColumn<ExpenseRow>[] = [
        {
            key: 'description',
            header: 'Description',
            width: 'minmax(240px,2.3fr)',
            cell: (e) => (
                <span className="cf-row t-label-strong flex-wrap">
                    {e.description}
                    {e.auto && <StatusPill status="auto" size="sm" />}
                </span>
            ),
        },
        {
            key: 'category',
            header: 'Category',
            width: '118px',
            className: 't-body t-muted',
            cell: (e) => e.categoryName,
        },
        {
            key: 'amount',
            header: 'Amount',
            width: '118px',
            numeric: true,
            sortable: true,
            firstSort: 'descending',
            cell: (e) => formatInr(e.amountPaise),
        },
        {
            key: 'date',
            header: 'Date',
            width: '104px',
            sortable: true,
            firstSort: 'descending',
            className: 't-body t-muted',
            cell: (e) => formatDate(e.date),
        },
        {
            key: 'submitted',
            header: 'Submitted by',
            width: '150px',
            cell: (e) => <SubmittedBy e={e} />,
        },
        {
            key: 'paid',
            header: 'Paid by',
            width: '178px',
            cell: (e) => <PaidBy e={e} />,
        },
        {
            key: 'status',
            header: 'Status',
            width: '118px',
            skeleton: 'pill',
            cell: (e) =>
                e.status === 'rejected' && e.rejectReason ? (
                    <Tooltip label={`Rejected: ${e.rejectReason}`}>
                        <span tabIndex={0}>
                            <StatusPill status="rejected" />
                        </span>
                    </Tooltip>
                ) : (
                    <StatusPill status={e.status} />
                ),
        },
    ];

    return (
        <>
            <PageHeader
                kicker="Finance"
                title="Expenses"
                crumbs={[{ title: 'Expenses' }]}
                actions={
                    <>
                        <FyMenu
                            fy={fy}
                            onPick={(start) =>
                                visit({ fy: start, month: null, page: null })
                            }
                        />
                        <a
                            href={exportUrl}
                            className={buttonClass({
                                variant: 'secondary',
                                size: 'lg',
                            })}
                            download
                        >
                            <Icon icon={Download} size={18} />
                            Export
                        </a>
                        {abilities.create && (
                            <Button
                                variant="primary"
                                size="lg"
                                disc={Plus}
                                onClick={() => onOpen(null)}
                            >
                                Add expense
                            </Button>
                        )}
                    </>
                }
            />

            <section className="ex-kpis" aria-label={fy.label}>
                <KpiTile
                    headingLevel="h2"
                    title="Total expenses"
                    icon={Receipt}
                    paise={total}
                    caption={`${fy.label} · ${formatIndianNumber(kpis.approvedCount + kpis.pendingCount)} entries`}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Approved"
                    icon={Check}
                    paise={kpis.approvedPaise}
                    caption={`${formatIndianNumber(kpis.approvedCount)} entries · rejected entries excluded`}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Pending approval"
                    icon={Clock}
                    paise={kpis.pendingPaise}
                    aside={
                        <span className="cf-pill cf-pill--warn">
                            {kpis.pendingCount === 0
                                ? 'Clear'
                                : `${kpis.pendingCount} waiting`}
                        </span>
                    }
                    caption={
                        abilities.ownOnly
                            ? 'Partners approve or reject these'
                            : 'Partners approve or reject below'
                    }
                />
            </section>

            <section
                className="cf-card flex min-w-0 flex-col gap-3.5"
                aria-label="Expenses"
            >
                {selected.size > 0 ? (
                    <BulkActionBar
                        count={selected.size}
                        noun={['expense', 'expenses']}
                        totalPaise={selectedRows.reduce(
                            (s, r) => s + r.amountPaise,
                            0,
                        )}
                        primary={{
                            label: 'Approve',
                            icon: Check,
                            onClick: onBulkApprove,
                            disabled: !abilities.approve,
                        }}
                        actions={[
                            ...(abilities.approve
                                ? [
                                      {
                                          label: 'Reject',
                                          icon: X,
                                          onClick: () => onReject(selectedRows),
                                      },
                                  ]
                                : []),
                            {
                                label: 'Export',
                                icon: Download,
                                onClick: exportSelected,
                            },
                        ]}
                        onClear={() => onSelect(new Set())}
                        busy={busy === 'bulk'}
                        busyLabel={`Approving ${selected.size} expenses…`}
                    />
                ) : (
                    <div className="cf-row flex-wrap gap-2">
                        <SearchField
                            variant="inline"
                            aria-label="Search expenses"
                            placeholder="Search expenses"
                            value={search}
                            onValueChange={onSearch}
                            wrapClassName="cf-inwrap--sm ex-search"
                        />
                        <Select
                            aria-label="Month"
                            pill
                            value={filters.month ?? ''}
                            onChange={(e) =>
                                visit({
                                    month: e.target.value || null,
                                    page: null,
                                })
                            }
                            options={[
                                { value: '', label: `All of ${fy.label}` },
                                ...fyMonths(fy, today).map((m) => ({
                                    value: m,
                                    label: monthLong(`${m}-01`),
                                })),
                            ]}
                        />
                        <Select
                            aria-label="Category"
                            pill
                            value={
                                filters.category ? String(filters.category) : ''
                            }
                            onChange={(e) =>
                                visit({
                                    category: e.target.value || null,
                                    page: null,
                                })
                            }
                            options={[
                                { value: '', label: 'All categories' },
                                ...options.categories.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                })),
                            ]}
                        />
                        {!abilities.ownOnly && (
                            <Select
                                aria-label="Paid by"
                                pill
                                value={filters.payer ?? ''}
                                onChange={(e) =>
                                    visit({
                                        payer: e.target.value || null,
                                        page: null,
                                    })
                                }
                                options={[
                                    { value: '', label: 'Anyone' },
                                    ...options.partners.map((p) => ({
                                        value: `partner:${p.id}`,
                                        label: p.name,
                                    })),
                                    ...options.members.map((m) => ({
                                        value: `member:${m.id}`,
                                        label: m.name,
                                    })),
                                    {
                                        value: 'company',
                                        label: 'Company account',
                                    },
                                ]}
                            />
                        )}
                        {options.projects.length > 1 && (
                            <Select
                                aria-label="Project"
                                pill
                                value={
                                    filters.project
                                        ? String(filters.project)
                                        : ''
                                }
                                onChange={(e) =>
                                    visit({
                                        project: e.target.value || null,
                                        page: null,
                                    })
                                }
                                options={[
                                    { value: '', label: 'All projects' },
                                    ...options.projects.map((p) => ({
                                        value: String(p.id),
                                        label: p.name,
                                    })),
                                ]}
                            />
                        )}
                        <FilterChipGroup
                            aria-label="Status"
                            className="ml-auto"
                        >
                            {(
                                [
                                    'all',
                                    'pending',
                                    'approved',
                                    'rejected',
                                ] as const
                            ).map((s) => (
                                <FilterChip
                                    key={s}
                                    pressed={(filters.status ?? 'all') === s}
                                    count={statusCounts[s]}
                                    onClick={() =>
                                        visit({
                                            status: s === 'all' ? null : s,
                                            page: null,
                                        })
                                    }
                                >
                                    {s === 'all'
                                        ? 'All'
                                        : s[0].toUpperCase() + s.slice(1)}
                                </FilterChip>
                            ))}
                        </FilterChipGroup>
                    </div>
                )}

                <DataTable
                    aria-label={`Expenses, ${where}`}
                    rows={expenses.data}
                    columns={columns}
                    getRowId={(e) => String(e.id)}
                    minWidth={1100}
                    selectable
                    selected={selected}
                    onSelectionChange={onSelect}
                    rowLabel={(e) => e.description}
                    sort={sort}
                    manualSort
                    onSortChange={(s) =>
                        visit({
                            sort: s.key === 'amount' ? 'amount' : null,
                            dir: s.direction === 'ascending' ? 'asc' : null,
                            page: null,
                        })
                    }
                    actionsWidth="148px"
                    rowActions={(e) => (
                        <span className="cf-rowacts">
                            {e.can.decide && (
                                <>
                                    <IconButton
                                        icon={Check}
                                        size="sm"
                                        variant="pos"
                                        aria-label={`Approve ${e.description}`}
                                        disabled={busy === `a${e.id}`}
                                        onClick={() => onApprove(e)}
                                    />
                                    <IconButton
                                        icon={X}
                                        size="sm"
                                        variant="neg"
                                        aria-label={`Reject ${e.description}`}
                                        onClick={() => onReject([e])}
                                    />
                                </>
                            )}
                            <IconButton
                                icon={Paperclip}
                                size="sm"
                                aria-label={`Receipt for ${e.description}`}
                                onClick={() => onReceipt(e)}
                            />
                            {e.can.edit && !e.can.decide && (
                                <IconButton
                                    icon={Pencil}
                                    size="sm"
                                    aria-label={`Edit ${e.description}`}
                                    onClick={() => onOpen(e)}
                                />
                            )}
                            {e.can.delete && (
                                <IconButton
                                    icon={Trash2}
                                    size="sm"
                                    aria-label={`Delete ${e.description}`}
                                    onClick={() => onDelete(e)}
                                />
                            )}
                        </span>
                    )}
                    empty={{
                        title: filtered
                            ? 'No expenses match these filters'
                            : `No expenses in ${fy.label} yet`,
                        icon: Receipt,
                        action: filtered ? (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => {
                                    setSearch('');
                                    visit({
                                        status: null,
                                        category: null,
                                        payer: null,
                                        month: null,
                                        project: null,
                                        q: null,
                                        page: null,
                                    });
                                }}
                            >
                                Clear filters
                            </Button>
                        ) : abilities.create ? (
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={Plus}
                                onClick={() => onOpen(null)}
                            >
                                Add expense
                            </Button>
                        ) : undefined,
                    }}
                />
                <PaginationBar
                    summary={`Showing ${expenses.data.length} of ${expenses.total} ${expenses.total === 1 ? 'expense' : 'expenses'} in ${where}`}
                >
                    {expenses.last_page > 1 && (
                        <Pager
                            page={expenses.current_page}
                            pageCount={expenses.last_page}
                            onPageChange={(page) => visit({ page })}
                        />
                    )}
                </PaginationBar>
            </section>
        </>
    );
}

type PhoneFilter = 'all' | 'mine' | 'pending';

/** "Today" · "Yesterday" · "12 Nov" */
function dayHeading(iso: string, today: string) {
    if (iso === today) {
        return 'Today';
    }

    const y = toDate(today);
    y.setDate(y.getDate() - 1);

    if (toDate(iso).toDateString() === y.toDateString()) {
        return 'Yesterday';
    }

    return formatDate(iso).replace(/ \d{4}$/, '');
}

function PhoneExpenses({
    today,
    filters,
    month,
    expenses,
    statusCounts,
    abilities,
    visit,
    onOpen,
    onReceipt,
}: SharedProps) {
    const current: PhoneFilter = filters.mine
        ? 'mine'
        : filters.status === 'pending'
          ? 'pending'
          : 'all';
    const days: string[] = [];

    expenses.data.forEach((e) => {
        if (!days.includes(e.date)) {
            days.push(e.date);
        }
    });

    return (
        <>
            <div className="ts-mtop">
                <h1 className="t-heading">Expenses</h1>
            </div>
            <SegmentedControl<PhoneFilter>
                aria-label="Show"
                value={current}
                className="w-full"
                onValueChange={(f) =>
                    visit({
                        mine: f === 'mine' ? 1 : null,
                        status: f === 'pending' ? 'pending' : null,
                        page: null,
                    })
                }
                options={[
                    { value: 'all', label: 'All', className: 'flex-1' },
                    ...(abilities.ownOnly
                        ? []
                        : [
                              {
                                  value: 'mine' as const,
                                  label: 'Mine',
                                  className: 'flex-1',
                              },
                          ]),
                    {
                        value: 'pending',
                        label: abilities.approve
                            ? `To approve · ${statusCounts.pending}`
                            : 'Pending',
                        className: 'flex-1',
                    },
                ]}
            />
            <section
                className="cf-card cf-kv items-center"
                aria-label={`${month.label} total`}
            >
                <span className="cf-stack">
                    <span className="t-caption t-muted">
                        {month.label} so far
                    </span>
                    <span
                        className="t-money"
                        style={{ fontSize: 22, lineHeight: '28px' }}
                    >
                        {formatInr(month.paise)}
                    </span>
                </span>
                <span className="t-caption t-muted">
                    {month.count} {month.count === 1 ? 'entry' : 'entries'} ·{' '}
                    {month.waiting} waiting
                </span>
            </section>
            {days.map((d) => (
                <section
                    key={d}
                    className="flex flex-col gap-2"
                    aria-label={dayHeading(d, today)}
                >
                    <h2 className="t-label t-muted mt-1.5 mb-0">
                        {dayHeading(d, today)}
                    </h2>
                    {expenses.data
                        .filter((e) => e.date === d)
                        .map((e) => (
                            <button
                                key={e.id}
                                type="button"
                                className="ex-mrow"
                                onClick={() => onReceipt(e)}
                                aria-label={`${e.description}, ${formatInr(e.amountPaise)}, ${e.status}`}
                            >
                                <span
                                    className="cf-avatar cf-avatar--tile"
                                    style={{
                                        background: 'var(--surface-muted)',
                                    }}
                                    aria-hidden="true"
                                >
                                    <Icon
                                        icon={e.auto ? RotateCw : Receipt}
                                        size={18}
                                    />
                                </span>
                                <span className="cf-stack min-w-0 text-left">
                                    <span className="t-label-strong ex-ellipsis">
                                        {e.description}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {e.categoryName} ·{' '}
                                        {e.auto
                                            ? 'Payroll'
                                            : (e.submittedBy?.name.split(
                                                  ' ',
                                              )[0] ?? '')}
                                    </span>
                                </span>
                                <span className="cf-stack items-end gap-1">
                                    <span className="t-money">
                                        {formatInr(e.amountPaise)}
                                    </span>
                                    <StatusPill status={e.status} size="sm" />
                                </span>
                            </button>
                        ))}
                </section>
            ))}
            {expenses.data.length === 0 && (
                <div className="cf-empty">
                    <span className="t-label-strong">
                        {current === 'pending'
                            ? 'Nothing to approve'
                            : 'No expenses yet'}
                    </span>
                    <span className="t-caption t-muted">
                        New expenses from the team show up here.
                    </span>
                </div>
            )}
            {expenses.last_page > 1 && (
                <Pager
                    page={expenses.current_page}
                    pageCount={expenses.last_page}
                    onPageChange={(page) => visit({ page })}
                />
            )}
            {abilities.create && (
                <button
                    type="button"
                    className="ex-fab"
                    aria-label="Add expense"
                    onClick={() => onOpen(null)}
                >
                    <Icon icon={Plus} size={22} strokeWidth={1.75} />
                </button>
            )}
        </>
    );
}
