import { Head, router } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowUpRight,
    ChartPie,
    Download,
    Landmark,
    Lock,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { CapitalModal } from '@/components/finance/capital-modal';
import type { CapitalKind } from '@/components/finance/capital-modal';
import { cleanQuery } from '@/components/finance/fy-menu';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Avatar, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button, buttonClass } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Money } from '@/components/ui/money';
import { Select } from '@/components/ui/select';
import { formatDate, formatInr } from '@/lib/format';
import {
    destroy as destroyMovement,
    exportMethod as exportStatements,
    index as capitalIndex,
} from '@/routes/capital-accounts';
import type {
    CapitalMovementRow,
    CapitalStatementRow,
    ClosableFiscalYear,
    FinancePartner,
    MoneyAccountOption,
} from '@/types/finance';

type Props = {
    company: { name: string; jv: boolean; pooled: boolean };
    today: string;
    fy: ClosableFiscalYear & { previousLabel: string | null };
    statement: CapitalStatementRow[];
    kpis: {
        closingPaise: number;
        contributionsPaise: number;
        contributionsCount: number;
        drawingsPaise: number;
        drawingsCount: number;
        netProfitPaise: number;
        asOf: string;
    };
    contributions: CapitalMovementRow[];
    drawings: CapitalMovementRow[];
    options: { partners: FinancePartner[]; accounts: MoneyAccountOption[] };
    abilities: { manage: boolean };
};

const pct = (bps: number) =>
    `${Number.isInteger(bps / 100) ? bps / 100 : (bps / 100).toFixed(1)}%`;

const entries = (n: number) => `${n} ${n === 1 ? 'entry' : 'entries'}`;

/**
 * Capital accounts (CapitalAccounts.dc.html, spec §11): the year's KPIs, each partner's
 * Opening + Contributions − Drawings + Share of profit = Closing from the books (grouped
 * by side in a JV), and the contribution and drawing ledgers with their Record modals.
 * Closed years are read-only.
 */
export default function CapitalAccounts(props: Props) {
    const { fy, statement, kpis, contributions, drawings, options, abilities } =
        props;
    const [modal, setModal] = useState<CapitalKind | null>(null);
    const [removing, setRemoving] = useState<
        (CapitalMovementRow & { kind: CapitalKind }) | null
    >(null);
    const [deleting, setDeleting] = useState(false);

    const partner = (id: number) =>
        options.partners.find((p) => p.id === id) ?? null;
    const sum = (key: keyof CapitalStatementRow) =>
        statement.reduce((s, r) => s + Number(r[key]), 0);
    const firstYear = fy.options[0]?.value === fy.start;
    const allOpeningZero = statement.every((r) => r.openingPaise === 0);
    const asOf = formatDate(kpis.asOf);
    const equityCaption = props.company.pooled
        ? `Shared by equity: ${statement.map((r) => Math.round(r.equityBps / 100)).join(' · ')}`
        : 'Shared by what each partner brings in';

    const money = (paise: number) => formatInr(paise);
    const columns: DataTableColumn<CapitalStatementRow>[] = [
        {
            key: 'partner',
            header: 'Partner',
            width: 'minmax(180px,1.4fr)',
            className: 'cf-row t-label-strong',
            cell: (r) => (
                <>
                    <Avatar
                        name={r.name}
                        size="sm"
                        partner={r.colour as PartnerColour}
                        label={false}
                    />
                    <span className="cf-stack">
                        <span>{r.name}</span>
                        <span className="t-caption t-muted font-normal">
                            {r.sideName ? `${r.sideName} · ` : ''}
                            {pct(r.equityBps)} equity
                        </span>
                    </span>
                </>
            ),
        },
        {
            key: 'opening',
            header: 'Opening',
            width: 'minmax(118px,1fr)',
            numeric: true,
            cell: (r) => money(r.openingPaise),
        },
        {
            key: 'contributions',
            header: '+ Contributions',
            width: 'minmax(118px,1fr)',
            numeric: true,
            cell: (r) => money(r.contributionsPaise),
        },
        {
            key: 'drawings',
            header: '− Drawings',
            width: 'minmax(118px,1fr)',
            numeric: true,
            cell: (r) => money(r.drawingsPaise),
        },
        {
            key: 'profit',
            header: '+ Share of profit',
            width: 'minmax(118px,1fr)',
            numeric: true,
            cell: (r) => money(r.profitPaise),
        },
        {
            key: 'closing',
            header: '= Closing',
            width: 'minmax(118px,1fr)',
            numeric: true,
            className: 'font-semibold',
            cell: (r) => money(r.closingPaise),
        },
    ];

    const ledger = (
        title: string,
        id: string,
        rows: CapitalMovementRow[],
        kind: CapitalKind,
    ) => (
        <article className="cf-card ca-ledger" aria-labelledby={id}>
            <h2 id={id} className="cf-dot-title t-title">
                {title}
            </h2>
            {rows.length === 0 ? (
                <p className="t-body t-muted m-0">
                    {kind === 'contribution'
                        ? `No contributions in ${fy.label}.`
                        : `No drawings in ${fy.label}.`}
                </p>
            ) : (
                rows.map((c) => {
                    const p = partner(c.partnerId);

                    return (
                        <div
                            key={c.id}
                            className={`cf-inset ca-entry${abilities.manage ? ' ca-entry--acts' : ''}`}
                        >
                            <span className="t-body t-muted">
                                {formatDate(c.date)}
                            </span>
                            <span className="cf-row t-body min-w-0">
                                <span
                                    className="cf-swatch"
                                    style={{
                                        background: p
                                            ? partnerColourVar(
                                                  p.colour as PartnerColour,
                                              )
                                            : 'var(--ink-faint)',
                                    }}
                                />
                                <span className="cf-stack min-w-0">
                                    <span className="font-medium">
                                        {p?.name}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {c.note ? `${c.note} · ` : ''}
                                        {kind === 'contribution'
                                            ? 'into'
                                            : 'from'}{' '}
                                        {c.accountName}
                                    </span>
                                </span>
                            </span>
                            <span
                                className={`t-money text-right${kind === 'contribution' ? ' t-pos' : ''}`}
                            >
                                {kind === 'contribution' ? '+' : '−'}
                                {formatInr(c.amountPaise)}
                            </span>
                            {abilities.manage && (
                                <IconButton
                                    icon={Trash2}
                                    size="sm"
                                    aria-label={`Delete ${kind} of ${formatInr(c.amountPaise)} for ${p?.name ?? 'partner'}`}
                                    onClick={() => setRemoving({ ...c, kind })}
                                />
                            )}
                        </div>
                    );
                })
            )}
        </article>
    );

    return (
        <>
            <Head title="Capital accounts" />
            <PageHeader
                kicker="Equity"
                title="Capital accounts"
                crumbs={[{ title: 'Capital accounts' }]}
                actions={
                    <>
                        <Select
                            aria-label="Financial year"
                            pill
                            wrapClassName="min-w-[160px]"
                            value={String(fy.start)}
                            onChange={(e) =>
                                router.get(
                                    capitalIndex().url,
                                    { fy: e.target.value },
                                    { preserveScroll: true, replace: true },
                                )
                            }
                            options={fy.options
                                .slice()
                                .reverse()
                                .map((o) => ({
                                    value: String(o.value),
                                    label: o.label,
                                }))}
                        />
                        {abilities.manage && (
                            <>
                                <Button
                                    variant="secondary"
                                    size="lg"
                                    onClick={() => setModal('drawing')}
                                >
                                    Record drawing
                                </Button>
                                <Button
                                    variant="primary"
                                    size="lg"
                                    disc={Plus}
                                    onClick={() => setModal('contribution')}
                                >
                                    Record contribution
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
                    Its statement is read-only. An Admin can reopen it in
                    Settings.
                </AlertBanner>
            )}

            <section className="ca-kpis" aria-label={fy.label}>
                <KpiTile
                    headingLevel="h2"
                    title="Total capital (closing)"
                    icon={Landmark}
                    paise={kpis.closingPaise}
                    caption={`${statement.length} ${statement.length === 1 ? 'partner' : 'partners'} · as of ${asOf}`}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Contributions this FY"
                    icon={ArrowDownRight}
                    paise={kpis.contributionsPaise}
                    caption={entries(kpis.contributionsCount)}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Drawings this FY"
                    icon={ArrowUpRight}
                    paise={kpis.drawingsPaise}
                    caption={entries(kpis.drawingsCount)}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Net profit YTD"
                    icon={ChartPie}
                    paise={kpis.netProfitPaise}
                    caption={equityCaption}
                />
            </section>

            <section
                className="cf-card flex min-w-0 flex-col gap-3"
                aria-labelledby="ca-h"
            >
                <div className="cf-kv flex-wrap">
                    <h2 id="ca-h" className="cf-dot-title t-title">
                        Partner statements · {fy.label}
                    </h2>
                    <a
                        href={
                            exportStatements({
                                query: cleanQuery({ fy: fy.start }),
                            }).url
                        }
                        className={buttonClass({
                            variant: 'secondary',
                            size: 'sm',
                        })}
                        download
                    >
                        <Icon icon={Download} size={14} />
                        Export statements
                    </a>
                </div>
                <DataTable
                    aria-label="Capital account statements"
                    rows={statement}
                    columns={columns}
                    getRowId={(r) => String(r.partnerId)}
                    minWidth={940}
                    totals={{
                        partner: 'Total',
                        opening: money(sum('openingPaise')),
                        contributions: money(sum('contributionsPaise')),
                        drawings: money(sum('drawingsPaise')),
                        profit: money(sum('profitPaise')),
                        closing: money(sum('closingPaise')),
                    }}
                    empty={{
                        title: 'No partners yet',
                        icon: Landmark,
                    }}
                />
                <p className="t-caption t-muted m-0">
                    Opening + Contributions − Drawings + Share of profit =
                    Closing.{' '}
                    {allOpeningZero && firstYear
                        ? `Opening balances are ₹0 because ${fy.label} is the ${props.company.jv ? 'venture’s' : 'company’s'} first year.`
                        : fy.previousLabel
                          ? `Opening is the closing capital of ${fy.previousLabel}.`
                          : ''}
                    {!fy.closed &&
                        ' Share of profit is this year’s so far; closing the year posts it into capital.'}
                </p>
            </section>

            <section className="ca-ledgers">
                {ledger('Contributions', 'ca-c', contributions, 'contribution')}
                {ledger('Drawings', 'ca-d', drawings, 'drawing')}
            </section>

            {abilities.manage && (
                <CapitalModal
                    kind={modal}
                    onClose={() => setModal(null)}
                    partners={options.partners}
                    accounts={options.accounts}
                    statement={statement}
                    today={props.today}
                />
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title={
                    removing?.kind === 'drawing'
                        ? 'Delete this drawing?'
                        : 'Delete this contribution?'
                }
                description={
                    removing ? (
                        <>
                            {partner(removing.partnerId)?.name} ·{' '}
                            <Money
                                paise={removing.amountPaise}
                                size="sm"
                                tone="none"
                            />{' '}
                            on {formatDate(removing.date)}. The entry is
                            reversed in the books.
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

                    router.delete(destroyMovement(removing.id).url, {
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
