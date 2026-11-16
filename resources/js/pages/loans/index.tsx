import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Clock,
    Coins,
    Ellipsis,
    Lock,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import { LoanModal, RepaymentModal } from '@/components/finance/loan-modals';
import type { LoanPartner } from '@/components/finance/loan-modals';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { FilterChip, FilterChipGroup } from '@/components/ui/filter-chips';
import { IconButton } from '@/components/ui/icon-button';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Money } from '@/components/ui/money';
import { ProgressBar } from '@/components/ui/progress-bar';
import { StatusPill } from '@/components/ui/status-pill';
import { formatDate, formatInr } from '@/lib/format';
import { index as capitalIndex } from '@/routes/capital-accounts';
import {
    destroy as destroyLoan,
    index as loansIndex,
} from '@/routes/partner-loans';
import { destroy as destroyRepayment } from '@/routes/partner-loans/repayments';
import type {
    ClosableFiscalYear,
    LoanRow,
    MoneyAccountOption,
    RepaymentRow,
} from '@/types/finance';

type Props = {
    company: { name: string; jv: boolean };
    today: string;
    fy: ClosableFiscalYear;
    loans: LoanRow[];
    kpis: {
        outstandingPaise: number;
        borrowedPaise: number;
        repaidPaise: number;
        loansCount: number;
        openCount: number;
        repaymentsCount: number;
        next: {
            code: string;
            amountPaise: number;
            date: string;
            by: boolean;
        } | null;
    };
    history: RepaymentRow[];
    options: { partners: LoanPartner[]; accounts: MoneyAccountOption[] };
    abilities: { manage: boolean };
};

type View = 'all' | 'open' | 'repaid';

/** "30 Nov" this year, "31 Mar 2027" otherwise. */
const shortDate = (iso: string, today: string) => {
    const text = formatDate(iso);

    return iso.slice(0, 4) === today.slice(0, 4)
        ? text.replace(/ \d{4}$/, '')
        : text;
};

const nextText = (
    next: { amountPaise: number; date: string; by: boolean } | null,
    today: string,
) =>
    next === null
        ? 'Nothing due'
        : `${formatInr(next.amountPaise)} · ${next.by ? 'by ' : ''}${shortDate(next.date, today)}`;

const pctOf = (l: LoanRow) =>
    l.amountPaise > 0 ? Math.round((l.repaidPaise / l.amountPaise) * 100) : 0;

/**
 * Partner loans (PartnerLoans.dc.html, spec §12): what is outstanding to partners with
 * a progress bar, KPIs, the loans (All / Open / Repaid) with progress, next payment and
 * status, the repayment history and the explainer card; Record loan (new money or a
 * personal-card balance, D51) and Record repayment. A loan closes on its own at ₹0.
 */
export default function PartnerLoans(props: Props) {
    const { fy, loans, kpis, history, options, abilities, today } = props;
    const [view, setView] = useState<View>('all');
    const [loanModal, setLoanModal] = useState(false);
    const [paying, setPaying] = useState<number | null>(null);
    const [removing, setRemoving] = useState<
        | { kind: 'loan'; row: LoanRow }
        | { kind: 'repayment'; row: RepaymentRow }
        | null
    >(null);
    const [deleting, setDeleting] = useState(false);

    const open = loans.filter((l) => l.outstandingPaise > 0);
    const rows = loans.filter((l) =>
        view === 'all'
            ? true
            : view === 'open'
              ? l.outstandingPaise > 0
              : l.outstandingPaise <= 0,
    );
    const pctAll =
        kpis.borrowedPaise > 0
            ? Math.round((kpis.repaidPaise / kpis.borrowedPaise) * 100)
            : 0;

    const columns: DataTableColumn<LoanRow>[] = [
        {
            key: 'loan',
            header: 'Loan',
            width: 'minmax(260px,2fr)',
            className: 'cf-row min-w-0 gap-2.5',
            cell: (l) => (
                <>
                    <Avatar
                        name={l.name}
                        partner={l.colour as PartnerColour}
                        label={false}
                    />
                    <span className="cf-stack min-w-0">
                        <span className="t-label-strong">
                            {l.code} · {l.name}
                        </span>
                        <span className="t-caption t-muted">
                            Lent {formatDate(l.date)}
                            {l.purpose
                                ? ` · ${l.purpose}`
                                : l.converted
                                  ? ' · from personal-card spending'
                                  : l.intoName
                                    ? ` · into ${l.intoName}`
                                    : ''}
                        </span>
                    </span>
                </>
            ),
        },
        {
            key: 'amount',
            header: 'Borrowed',
            width: '120px',
            numeric: true,
            cell: (l) => formatInr(l.amountPaise),
        },
        {
            key: 'repaid',
            header: 'Repaid',
            width: '120px',
            numeric: true,
            className: 't-muted',
            cell: (l) => formatInr(l.repaidPaise),
        },
        {
            key: 'out',
            header: 'Outstanding',
            width: '120px',
            numeric: true,
            className: 'font-medium',
            cell: (l) => formatInr(Math.max(0, l.outstandingPaise)),
        },
        {
            key: 'progress',
            header: 'Progress',
            width: 'minmax(150px,1fr)',
            className: 'cf-row gap-2',
            cell: (l) => (
                <>
                    <ProgressBar
                        value={pctOf(l)}
                        variant={l.outstandingPaise <= 0 ? 'pos' : 'lime'}
                        aria-label={`${l.code} repaid`}
                        className="flex-1"
                    />
                    <span className="t-money-sm w-9 text-right">
                        {pctOf(l)}%
                    </span>
                </>
            ),
        },
        {
            key: 'next',
            header: 'Next payment',
            width: 'minmax(170px,1fr)',
            className: 'cf-stack',
            cell: (l) => (
                <>
                    <span className="t-body">{nextText(l.next, today)}</span>
                    <span className="t-caption t-muted">{l.terms}</span>
                </>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '120px',
            skeleton: 'pill',
            cell: (l) =>
                l.outstandingPaise <= 0 ? (
                    <StatusPill tone="pos" icon={Check}>
                        Repaid
                    </StatusPill>
                ) : l.repaidPaise > 0 ? (
                    <StatusPill tone="info" icon={ArrowRight}>
                        Repaying
                    </StatusPill>
                ) : (
                    <StatusPill tone="warn" icon={Clock}>
                        Open
                    </StatusPill>
                ),
        },
    ];

    return (
        <>
            <Head title="Partner loans" />
            <PageHeader
                kicker="Finance"
                title="Partner loans"
                crumbs={[{ title: 'Partner loans' }]}
                actions={
                    <>
                        <FyMenu
                            fy={fy}
                            onPick={(start) =>
                                router.get(
                                    loansIndex().url,
                                    cleanQuery({ fy: start }),
                                    { preserveScroll: true, replace: true },
                                )
                            }
                        />
                        {abilities.manage && (
                            <>
                                <Button
                                    variant="secondary"
                                    size="lg"
                                    icon={Plus}
                                    onClick={() => setLoanModal(true)}
                                >
                                    Record loan
                                </Button>
                                <Button
                                    variant="primary"
                                    size="lg"
                                    disc={ArrowRight}
                                    disabled={open.length === 0}
                                    onClick={() =>
                                        open[0] && setPaying(open[0].id)
                                    }
                                >
                                    Record repayment
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            {fy.closed && (
                <AlertBanner
                    variant="info"
                    icon={Lock}
                    title={`${fy.label} is closed`}
                    role="status"
                >
                    Loans and repayments dated in it can’t change. An Admin can
                    reopen it in Settings.
                </AlertBanner>
            )}

            <section className="ln-top">
                <article className="cf-card cf-card--lime ln-hero">
                    <div className="ln-hero-glow" aria-hidden="true" />
                    <span className="t-label-strong ln-hero-tag">
                        Outstanding to partners
                    </span>
                    <div className="ln-hero-figure">
                        <span className="t-money-unit ln-hero-unit">₹</span>
                        <span className="t-figure-hero">
                            {formatInr(kpis.outstandingPaise, {
                                symbol: false,
                            })}
                        </span>
                    </div>
                    <div className="ln-hero-foot">
                        <ProgressBar
                            value={pctAll}
                            aria-label="Share of borrowed money repaid"
                            fill="var(--on-lime)"
                            className="ln-hero-bar"
                        />
                        <span className="t-body">
                            {pctAll}% of{' '}
                            <span className="t-money">
                                {formatInr(kpis.borrowedPaise)}
                            </span>{' '}
                            paid back
                        </span>
                    </div>
                </article>
                <div className="ln-kpis">
                    <KpiTile
                        headingLevel="h2"
                        title="Total borrowed"
                        icon={Coins}
                        paise={kpis.borrowedPaise}
                        caption={`${kpis.loansCount} ${kpis.loansCount === 1 ? 'loan' : 'loans'} in ${fy.label}`}
                    />
                    <KpiTile
                        headingLevel="h2"
                        title="Repaid"
                        icon={Check}
                        paise={kpis.repaidPaise}
                        caption={`${kpis.repaymentsCount} ${kpis.repaymentsCount === 1 ? 'repayment' : 'repayments'}`}
                    />
                    <KpiTile
                        headingLevel="h2"
                        title="Open loans"
                        icon={Clock}
                        value={String(kpis.openCount)}
                        caption={
                            kpis.next
                                ? `Next: ${nextText(kpis.next, today)}`
                                : kpis.openCount > 0
                                  ? 'No fixed dates'
                                  : 'All loans repaid'
                        }
                    />
                </div>
            </section>

            <section
                className="cf-card flex min-w-0 flex-col gap-3"
                aria-labelledby="ln-h"
            >
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="ln-h" className="cf-dot-title t-title">
                        Loans
                    </h2>
                    <FilterChipGroup aria-label="Show">
                        {(
                            [
                                ['all', 'All', loans.length],
                                ['open', 'Open', open.length],
                                [
                                    'repaid',
                                    'Repaid',
                                    loans.length - open.length,
                                ],
                            ] as const
                        ).map(([value, label, n]) => (
                            <FilterChip
                                key={value}
                                pressed={view === value}
                                count={n}
                                onClick={() => setView(value)}
                            >
                                {label}
                            </FilterChip>
                        ))}
                    </FilterChipGroup>
                </div>
                <DataTable
                    aria-label="Partner loans"
                    rows={rows}
                    columns={columns}
                    getRowId={(l) => String(l.id)}
                    minWidth={1180}
                    actionsHeader="Actions"
                    actionsWidth="200px"
                    rowActions={(l) => (
                        <span className="cf-rowacts">
                            {l.outstandingPaise > 0 ? (
                                abilities.manage && (
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        aria-label={`Record repayment for ${l.code}`}
                                        onClick={() => setPaying(l.id)}
                                    >
                                        Record repayment
                                    </Button>
                                )
                            ) : (
                                <span className="t-caption t-muted">
                                    {l.closedOn
                                        ? `Closed ${formatDate(l.closedOn)}`
                                        : 'Closed'}
                                </span>
                            )}
                            {abilities.manage && l.repaymentsCount === 0 && (
                                <DropdownMenu
                                    aria-label={`Actions for ${l.code}`}
                                    align="end"
                                    trigger={
                                        <IconButton
                                            icon={Ellipsis}
                                            size="sm"
                                            aria-label={`More actions for ${l.code}`}
                                        />
                                    }
                                    entries={[
                                        {
                                            label: 'Delete loan',
                                            icon: Trash2,
                                            danger: true,
                                            onSelect: () =>
                                                setRemoving({
                                                    kind: 'loan',
                                                    row: l,
                                                }),
                                        },
                                    ]}
                                />
                            )}
                        </span>
                    )}
                    empty={{
                        title:
                            loans.length === 0
                                ? `No partner loans in ${fy.label}`
                                : 'No loans in this view.',
                        icon: Coins,
                        action:
                            loans.length === 0 && abilities.manage ? (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Plus}
                                    onClick={() => setLoanModal(true)}
                                >
                                    Record loan
                                </Button>
                            ) : undefined,
                    }}
                />
            </section>

            <section className="ln-bottom">
                <article className="cf-card ln-history" aria-labelledby="rh-h">
                    <h2 id="rh-h" className="cf-dot-title t-title">
                        Repayment history
                    </h2>
                    {history.length === 0 ? (
                        <p className="t-body t-muted m-0">
                            No repayments in {fy.label}.
                        </p>
                    ) : (
                        history.map((h) => (
                            <div
                                key={h.id}
                                className={`cf-inset ln-hrow${abilities.manage ? ' ln-hrow--acts' : ''}`}
                            >
                                <span className="t-body t-muted">
                                    {formatDate(h.date)}
                                </span>
                                <span className="cf-row min-w-0 gap-2">
                                    <Avatar
                                        name={h.name}
                                        size="sm"
                                        partner={h.colour as PartnerColour}
                                        label={false}
                                    />
                                    <span className="t-label-strong">
                                        Repaid to {h.name}
                                    </span>
                                    <span className="cf-pill cf-pill--outline cf-pill--sm t-money-sm">
                                        {h.code}
                                    </span>
                                </span>
                                <span className="t-money text-right">
                                    {formatInr(h.amountPaise)}
                                </span>
                                <span className="t-caption t-muted">
                                    from {h.fromName}
                                    {h.note ? ` · ${h.note}` : ''}
                                </span>
                                {abilities.manage && (
                                    <IconButton
                                        icon={Trash2}
                                        size="sm"
                                        aria-label={`Delete the ${formatInr(h.amountPaise)} repayment on ${h.code}`}
                                        onClick={() =>
                                            setRemoving({
                                                kind: 'repayment',
                                                row: h,
                                            })
                                        }
                                    />
                                )}
                            </div>
                        ))
                    )}
                </article>
                <aside className="cf-card cf-card--graphite ln-explain">
                    <h2 className="t-title m-0">How partner loans work</h2>
                    <p className="t-body m-0 opacity-80">
                        A loan is money a partner lends the company. It sits
                        under liabilities on the balance sheet, not in the
                        partner’s capital account, so it never changes equity or
                        profit share.
                    </p>
                    <p className="t-body m-0 opacity-80">
                        Repayments go back to the partner who lent. When the
                        outstanding amount reaches ₹0 the loan closes on its
                        own.
                    </p>
                    <Link
                        href={capitalIndex().url}
                        className="cf-link"
                        style={{ color: 'inherit' }}
                    >
                        Go to capital accounts
                    </Link>
                </aside>
            </section>

            {abilities.manage && (
                <>
                    <LoanModal
                        open={loanModal}
                        onClose={() => setLoanModal(false)}
                        partners={options.partners}
                        accounts={options.accounts}
                        today={today}
                    />
                    <RepaymentModal
                        loanId={paying}
                        onClose={() => setPaying(null)}
                        loans={loans}
                        accounts={options.accounts}
                        today={today}
                    />
                </>
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title={
                    removing?.kind === 'loan'
                        ? `Delete ${removing.row.code}?`
                        : 'Delete this repayment?'
                }
                description={
                    removing ? (
                        <>
                            {removing.row.name} ·{' '}
                            <Money
                                paise={removing.row.amountPaise}
                                size="sm"
                                tone="none"
                            />{' '}
                            on {formatDate(removing.row.date)}.{' '}
                            {removing.kind === 'repayment'
                                ? 'The entry is reversed and the loan’s outstanding amount goes back up.'
                                : 'The entry is reversed in the books.'}
                        </>
                    ) : undefined
                }
                icon={Trash2}
                confirmLabel="Delete"
                loading={deleting}
                onConfirm={() => {
                    if (!removing) {
                        return;
                    }

                    router.delete(
                        removing.kind === 'loan'
                            ? destroyLoan(removing.row.id).url
                            : destroyRepayment(removing.row.id).url,
                        {
                            preserveScroll: true,
                            onStart: () => setDeleting(true),
                            onFinish: () => {
                                setDeleting(false);
                                setRemoving(null);
                            },
                        },
                    );
                }}
            />
        </>
    );
}
