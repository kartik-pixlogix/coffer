import { Head, router } from '@inertiajs/react';
import {
    Download,
    Ellipsis,
    FileText,
    Pencil,
    Plus,
    Trash2,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import { IncomeModal } from '@/components/finance/income-modal';
import { PageHeader } from '@/components/shell/page-header';
import { initialsFrom, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { BarChart } from '@/components/ui/bar-chart';
import { Button, buttonClass } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn, SortState } from '@/components/ui/data-table';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { FilterChip, FilterChipGroup } from '@/components/ui/filter-chips';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Money } from '@/components/ui/money';
import { Pager, PaginationBar } from '@/components/ui/pagination';
import { TrendChip } from '@/components/ui/trend-chip';
import { formatDate, formatInr } from '@/lib/format';
import { firstName } from '@/lib/team';
import {
    destroy as deleteIncome,
    exportMethod as exportIncome,
    index as incomeIndex,
} from '@/routes/income';
import type {
    FiscalYearProps,
    IncomeOptions,
    IncomeRow,
    Paginated,
} from '@/types/finance';

type Sort = 'date' | 'amount' | 'source' | 'partner' | 'category';

type Filters = { category?: number; sort?: Sort; dir?: 'asc' };

type Props = {
    company: { name: string; jv: boolean; pooled: boolean };
    today: string;
    fy: FiscalYearProps;
    filters: Filters;
    kpis: {
        totalPaise: number;
        previousPaise: number;
        entries: number;
        activeMonths: number;
    };
    trend: { month: string; paise: number }[];
    chips: { id: number; name: string; count: number }[];
    incomes: Paginated<IncomeRow>;
    options: IncomeOptions;
    abilities: { manage: boolean };
};

/** Income.dc.html's category pills: Project info, Product positive, Consulting warning. */
const PILL: Record<string, string> = {
    Project: 'cf-pill--info',
    Product: 'cf-pill--pos',
    Consulting: 'cf-pill--warn',
};

/**
 * Income (Income.dc.html, spec §9): the year's total, entries and active months, the
 * monthly trend, and the entries sortable by date, amount, source, partner and
 * category, with add, edit and delete (each posting through the ledger).
 */
export default function Income(props: Props) {
    const { fy, filters, kpis, trend, chips, incomes, options, abilities } =
        props;
    const [modal, setModal] = useState(false);
    const [editing, setEditing] = useState<IncomeRow | null>(null);
    const [removing, setRemoving] = useState<IncomeRow | null>(null);
    const [deleting, setDeleting] = useState(false);

    const query = { fy: fy.start, ...filters };
    const visit = (next: Record<string, string | number | null | undefined>) =>
        router.get(incomeIndex().url, cleanQuery({ ...query, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const open = (row: IncomeRow | null) => {
        setEditing(row);
        setModal(true);
    };

    const partner = (id: number | null) =>
        options.partners.find((p) => p.id === id) ?? null;
    const categoryName = (id: number) =>
        options.categories.find((c) => c.id === id)?.name ?? '';
    const total = chips.reduce((s, c) => s + c.count, 0);

    const sort: SortState = {
        key: filters.sort ?? 'date',
        direction: filters.dir === 'asc' ? 'ascending' : 'descending',
    };
    const change =
        kpis.previousPaise > 0
            ? ((kpis.totalPaise - kpis.previousPaise) / kpis.previousPaise) *
              100
            : null;
    const selectedMonth = trend.reduce(
        (last, m, i) => (m.paise !== 0 ? i : last),
        trend.length - 1,
    );

    const columns: DataTableColumn<IncomeRow>[] = [
        {
            key: 'source',
            header: 'Source',
            width: 'minmax(260px,2fr)',
            sortable: true,
            firstSort: 'ascending',
            cell: (r) => (
                <span className="cf-stack">
                    <span className="t-label-strong">{r.source}</span>
                    <span className="t-caption t-muted">
                        Into {r.intoLabel}
                        {r.projectName ? ` · ${r.projectName}` : ''}
                    </span>
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            width: '130px',
            numeric: true,
            sortable: true,
            firstSort: 'descending',
            className: 't-pos font-medium',
            cell: (r) => formatInr(r.amountPaise),
        },
        {
            key: 'date',
            header: 'Date',
            width: '120px',
            sortable: true,
            firstSort: 'descending',
            className: 't-body t-muted',
            cell: (r) => formatDate(r.date),
        },
        {
            key: 'partner',
            header: 'Partner',
            width: '160px',
            sortable: true,
            firstSort: 'ascending',
            cell: (r) => {
                const p = partner(r.broughtInBy);

                return p ? (
                    <span className="cf-row t-body">
                        <span
                            className="cf-avatar cf-avatar--sm"
                            aria-hidden="true"
                        >
                            {initialsFrom(p.name)}
                            <span
                                className="cf-avatar-dot"
                                style={{
                                    background: partnerColourVar(
                                        p.colour as PartnerColour,
                                    ),
                                }}
                            />
                        </span>
                        {firstName(p.name)}
                    </span>
                ) : (
                    <span className="t-body t-muted">Pooled</span>
                );
            },
        },
        {
            key: 'category',
            header: 'Category',
            width: '130px',
            sortable: true,
            firstSort: 'ascending',
            skeleton: 'pill',
            cell: (r) => {
                const name = categoryName(r.categoryId);

                return (
                    <span className={`cf-pill ${PILL[name] ?? ''}`}>
                        {name}
                    </span>
                );
            },
        },
    ];

    return (
        <>
            <Head title="Income" />
            <PageHeader
                kicker="Finance"
                title="Income"
                crumbs={[{ title: 'Income' }]}
                actions={
                    <>
                        <FyMenu
                            fy={fy}
                            onPick={(start) =>
                                visit({ fy: start, category: null })
                            }
                        />
                        <a
                            href={
                                exportIncome({
                                    query: cleanQuery({
                                        fy: fy.start,
                                        category: filters.category,
                                    }),
                                }).url
                            }
                            className={buttonClass({
                                variant: 'secondary',
                                size: 'lg',
                            })}
                            download
                        >
                            <Icon icon={Download} size={18} />
                            Export
                        </a>
                        {abilities.manage && (
                            <Button
                                variant="primary"
                                size="lg"
                                disc={Plus}
                                onClick={() => open(null)}
                            >
                                Add income
                            </Button>
                        )}
                    </>
                }
            />

            <section className="in-top">
                <div className="in-kpis">
                    <KpiTile
                        headingLevel="h2"
                        title="Total income"
                        icon={TrendingUp}
                        paise={kpis.totalPaise}
                        aside={
                            change !== null && (
                                <TrendChip
                                    percent={change}
                                    comparedTo={`the same span of ${fy.options.find((o) => o.value === fy.start - 1)?.label ?? 'last year'}`}
                                />
                            )
                        }
                        caption={fy.current ? `${fy.label} to date` : fy.label}
                    />
                    <div className="in-kpi-pair">
                        <KpiTile
                            headingLevel="h2"
                            title="Entries"
                            icon={FileText}
                            value={kpis.entries.toLocaleString('en-IN')}
                        />
                        <KpiTile
                            headingLevel="h2"
                            title="Active months"
                            icon={TrendingUp}
                            value={String(kpis.activeMonths)}
                        />
                    </div>
                </div>
                <article className="cf-card in-trend">
                    <BarChart
                        title="Monthly trend"
                        metric="income"
                        data={trend.map((m) => ({
                            date: `${m.month}-01`,
                            value: m.paise,
                        }))}
                        selectedIndex={trend.length > 0 ? selectedMonth : null}
                        barMaxHeight={132}
                        emptyTitle="No income yet"
                        emptyText="Bars appear once income is added."
                    />
                </article>
            </section>

            <section
                className="cf-card flex min-w-0 flex-col gap-3"
                aria-labelledby="in-h"
            >
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="in-h" className="cf-dot-title t-title">
                        Latest entries
                    </h2>
                    <FilterChipGroup aria-label="Category">
                        <FilterChip
                            pressed={filters.category === undefined}
                            count={total}
                            onClick={() =>
                                visit({ category: null, page: null })
                            }
                        >
                            All
                        </FilterChip>
                        {chips.map((c) => (
                            <FilterChip
                                key={c.id}
                                pressed={filters.category === c.id}
                                count={c.count}
                                onClick={() =>
                                    visit({ category: c.id, page: null })
                                }
                            >
                                {c.name}
                            </FilterChip>
                        ))}
                    </FilterChipGroup>
                </div>
                <DataTable
                    aria-label="Latest income entries"
                    rows={incomes.data}
                    columns={columns}
                    getRowId={(r) => String(r.id)}
                    minWidth={920}
                    sort={sort}
                    manualSort
                    onSortChange={(s) =>
                        visit({
                            sort: s.key === 'date' ? null : s.key,
                            dir: s.direction === 'ascending' ? 'asc' : null,
                            page: null,
                        })
                    }
                    actionsWidth="80px"
                    rowActions={(r) => (
                        <span className="cf-rowacts">
                            <DropdownMenu
                                aria-label={`Actions for ${r.source}`}
                                align="end"
                                trigger={
                                    <IconButton
                                        icon={Ellipsis}
                                        size="sm"
                                        aria-label={`More actions for ${r.source}`}
                                    />
                                }
                                entries={[
                                    ...(abilities.manage
                                        ? [
                                              {
                                                  label: 'Edit',
                                                  icon: Pencil,
                                                  onSelect: () => open(r),
                                              },
                                          ]
                                        : []),
                                    ...(r.attachment
                                        ? [
                                              {
                                                  label: 'Open invoice',
                                                  icon: FileText,
                                                  onSelect: () =>
                                                      window.open(
                                                          r.attachment?.url,
                                                          '_blank',
                                                          'noopener',
                                                      ),
                                              },
                                          ]
                                        : []),
                                    ...(abilities.manage
                                        ? [
                                              {
                                                  label: 'Delete',
                                                  icon: Trash2,
                                                  danger: true,
                                                  onSelect: () =>
                                                      setRemoving(r),
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </span>
                    )}
                    empty={{
                        title:
                            filters.category !== undefined
                                ? 'No income in this category'
                                : `No income in ${fy.label} yet`,
                        icon: TrendingUp,
                        action:
                            filters.category !== undefined ? (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() => visit({ category: null })}
                                >
                                    Show all
                                </Button>
                            ) : abilities.manage ? (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Plus}
                                    onClick={() => open(null)}
                                >
                                    Add income
                                </Button>
                            ) : undefined,
                    }}
                />
                <PaginationBar
                    summary={`Showing ${incomes.data.length} of ${incomes.total} ${incomes.total === 1 ? 'entry' : 'entries'} in ${fy.label}`}
                >
                    {incomes.last_page > 1 && (
                        <Pager
                            page={incomes.current_page}
                            pageCount={incomes.last_page}
                            onPageChange={(page) => visit({ page })}
                        />
                    )}
                </PaginationBar>
            </section>

            {abilities.manage && (
                <IncomeModal
                    open={modal}
                    onOpenChange={setModal}
                    income={editing}
                    options={options}
                    company={props.company}
                    today={props.today}
                />
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title="Delete this income?"
                description={
                    removing ? (
                        <>
                            {removing.source} ·{' '}
                            <Money
                                paise={removing.amountPaise}
                                size="sm"
                                tone="none"
                            />{' '}
                            on {formatDate(removing.date)}. The entry is
                            reversed in the books, so the figures update
                            straight away.
                        </>
                    ) : undefined
                }
                icon={Trash2}
                confirmLabel="Delete income"
                loading={deleting}
                onConfirm={() => {
                    if (!removing) {
                        return;
                    }

                    router.delete(deleteIncome(removing.id).url, {
                        preserveScroll: true,
                        onStart: () => setDeleting(true),
                        onFinish: () => {
                            setDeleting(false);
                            setRemoving(null);
                        },
                    });
                }}
            />
        </>
    );
}
