import { Head, router } from '@inertiajs/react';
import {
    Coins,
    Download,
    FileText,
    Grid3x3,
    Receipt,
    TrendingUp,
} from 'lucide-react';
import { useEffect } from 'react';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import {
    periodName,
    PeriodMenu,
    presetQuery,
} from '@/components/reports/period-menu';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Avatar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { BarChart } from '@/components/ui/bar-chart';
import { buttonClass } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { Icon } from '@/components/ui/icon';
import { KpiTile } from '@/components/ui/kpi-tile';
import { Money } from '@/components/ui/money';
import { ProgressBar } from '@/components/ui/progress-bar';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { formatInr, MINUS } from '@/lib/format';
import {
    exportMethod as exportReport,
    index as reportsIndex,
} from '@/routes/reports';
import type {
    PartnerPnlRow,
    PeriodQuery,
    PnlMonth,
    ProfitAndLossData,
    ReportExportRow,
    ReportPeriodProps,
    ReportPreset,
} from '@/types/reports';

type Props = {
    company: { name: string; jv: boolean; pooled: boolean };
    period: ReportPeriodProps;
    query: PeriodQuery;
    report: ProfitAndLossData;
    /** Big periods export through a queued job (D61). */
    exportQueued: boolean;
    exports: ReportExportRow[];
};

/** Basis points as "29.7%", "−4.2%" or "—". */
export const bpsText = (bps: number | null): string =>
    bps === null
        ? '—'
        : `${bps < 0 ? MINUS : ''}${(Math.abs(bps) / 100).toFixed(1)}%`;

const monthLabel = (month: string) =>
    new Date(`${month}-01T00:00:00`).toLocaleString('en-IN', {
        month: 'long',
        year: 'numeric',
    });

const clampPct = (bps: number | null) =>
    bps === null ? 0 : Math.max(0, Math.min(100, Math.round(bps / 100)));

/** Poll queued exports this often until they're ready. */
const POLL_MS = 2000;

/**
 * Reports (Reports.dc.html, spec §15): the P&L for a period — FY to date, a quarter, a
 * month or a custom range — with KPIs, ratios, monthly profit (the period's bars lit),
 * the month-by-month table, partner P&L, and PDF / Excel export (queued when large).
 */
export default function Reports({
    company,
    period,
    query,
    report,
    exportQueued,
    exports,
}: Props) {
    const { kpis } = report;
    const go = (q: PeriodQuery) =>
        router.get(reportsIndex().url, cleanQuery(q), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

    const pending = exports.some((e) => e.status === 'queued');

    useEffect(() => {
        if (!pending) {
            return;
        }

        const timer = window.setInterval(
            () => router.reload({ only: ['exports'] }),
            POLL_MS,
        );

        return () => window.clearInterval(timer);
    }, [pending]);

    const segments: { value: ReportPreset; label: string }[] = [
        {
            value: 'fy',
            label: period.fy.current ? 'Year to date' : 'Full year',
        },
        ...period.quarters.map((q) => ({ value: q.value, label: q.label })),
    ];
    const segmentValue = segments.some((s) => s.value === period.preset)
        ? period.preset
        : ('' as ReportPreset);

    const exportUrl = (format: 'pdf' | 'xlsx') =>
        exportReport({ query: { ...cleanQuery(query), format } }).url;

    const startExport = (format: 'pdf' | 'xlsx') => {
        if (exportQueued) {
            router.get(exportUrl(format), {}, { preserveScroll: true });
        } else {
            window.location.assign(exportUrl(format));
        }
    };

    const barIndexes = report.bars
        .map((b, i) => (b.inPeriod ? i : -1))
        .filter((i) => i >= 0);

    const monthColumns: DataTableColumn<PnlMonth>[] = [
        {
            key: 'month',
            header: 'Month',
            width: '150px',
            className: 't-label-strong',
            cell: (m) => monthLabel(m.month),
        },
        ...(
            [
                ['income', 'Revenue'],
                ['expenses', 'Expenses'],
                ['other', 'Other'],
                ['salary', 'Salaries'],
            ] as const
        ).map(([key, header]) => ({
            key,
            header,
            width: 'minmax(110px,1fr)',
            numeric: true,
            cell: (m: PnlMonth) => formatInr(m[key]),
        })),
        {
            key: 'net',
            header: 'Profit',
            width: 'minmax(110px,1fr)',
            numeric: true,
            className: (m) => `font-medium ${m.net < 0 ? 't-neg' : ''}`,
            cell: (m) => formatInr(m.net),
        },
        {
            key: 'margin',
            header: 'Margin',
            width: '90px',
            numeric: true,
            className: (m) => (m.net < 0 ? 't-neg' : undefined),
            cell: (m) => bpsText(m.marginBps),
        },
    ];

    const partnerColumns: DataTableColumn<PartnerPnlRow>[] = [
        {
            key: 'partner',
            header: 'Partner',
            width: 'minmax(200px,1.5fr)',
            className: 'cf-row t-label-strong',
            cell: (p) => (
                <>
                    <Avatar
                        name={p.name}
                        size="sm"
                        partner={p.colour as PartnerColour}
                        label={false}
                    />
                    <span className="cf-stack">
                        <span>{p.name}</span>
                        {p.side && (
                            <span className="t-caption t-muted font-normal">
                                {p.side}
                                {p.sideShareBps !== null &&
                                    ` · ${bpsText(p.sideShareBps).replace('.0%', '%')} of side`}
                            </span>
                        )}
                    </span>
                </>
            ),
        },
        {
            key: 'equity',
            header: 'Equity',
            width: '80px',
            numeric: true,
            cell: (p) => bpsText(p.equityBps).replace('.0%', '%'),
        },
        {
            key: 'revenue',
            header: 'Revenue generated',
            width: 'minmax(120px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.revenue),
        },
        {
            key: 'expenses',
            header: 'Expenses',
            width: 'minmax(120px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.expenses),
        },
        {
            key: 'other',
            header: 'Other',
            width: 'minmax(120px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.other),
        },
        {
            key: 'net',
            header: 'Net contribution',
            width: 'minmax(120px,1fr)',
            numeric: true,
            className: (p) => `font-medium ${p.net < 0 ? 't-neg' : ''}`,
            cell: (p) => formatInr(p.net),
        },
        {
            key: 'due',
            header: 'Profit due',
            width: 'minmax(120px,1fr)',
            numeric: true,
            className: 'font-medium',
            cell: (p) => formatInr(p.profitDue),
        },
        {
            key: 'share',
            header: 'Revenue share',
            width: '110px',
            numeric: true,
            cell: (p) => bpsText(p.revenueShareBps),
        },
    ];

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                kicker="Reports"
                title="Profit & loss"
                crumbs={[{ title: 'Reports' }]}
                description={`${report.range} · ${report.fyLabel}`}
                actions={
                    <>
                        <SegmentedControl
                            aria-label="Period"
                            className="rp-seg"
                            options={segments}
                            value={segmentValue}
                            onValueChange={(v) => go(presetQuery(period, v))}
                        />
                        <PeriodMenu
                            period={period}
                            onChange={go}
                            include={{ fy: false, quarters: false }}
                            label={
                                period.preset === 'month' ||
                                period.preset === 'custom'
                                    ? periodName(period)
                                    : 'Month or range'
                            }
                        />
                        <FyMenu
                            fy={period.fy}
                            onPick={(start) => go({ fy: start })}
                        />
                        <DropdownMenu
                            aria-label="Export"
                            align="end"
                            trigger={
                                <button
                                    type="button"
                                    className={buttonClass({
                                        variant: 'primary',
                                        size: 'lg',
                                        disc: Download,
                                    })}
                                >
                                    Export
                                    <span
                                        className="cf-disc"
                                        aria-hidden="true"
                                    >
                                        <Icon
                                            icon={Download}
                                            size={18}
                                            strokeWidth={1.75}
                                        />
                                    </span>
                                </button>
                            }
                            entries={[
                                {
                                    type: 'label',
                                    label: `P&L · ${report.range}`,
                                },
                                {
                                    label: 'PDF report',
                                    icon: FileText,
                                    onSelect: () => startExport('pdf'),
                                },
                                {
                                    label: 'Excel workbook (.xlsx)',
                                    icon: Grid3x3,
                                    onSelect: () => startExport('xlsx'),
                                },
                                { type: 'separator' },
                                {
                                    type: 'label',
                                    key: 'note',
                                    label: exportQueued
                                        ? 'Large period: it’s prepared in the background.'
                                        : 'Both include the monthly and partner tables.',
                                },
                            ]}
                        />
                    </>
                }
            />

            {exports.map((e) =>
                e.status === 'ready' && e.url ? (
                    <AlertBanner
                        key={e.id}
                        variant="pos"
                        slim
                        title={`${e.label} is ready`}
                        role="status"
                        action={
                            <a
                                href={e.url}
                                className={buttonClass({
                                    variant: 'secondary',
                                })}
                            >
                                Download
                            </a>
                        }
                    />
                ) : e.status === 'queued' ? (
                    <AlertBanner
                        key={e.id}
                        variant="info"
                        slim
                        title={`Preparing ${e.label}`}
                        role="status"
                        action={
                            <span className="cf-spinner" aria-hidden="true" />
                        }
                    >
                        It appears here to download in a moment.
                    </AlertBanner>
                ) : e.status === 'failed' ? (
                    <AlertBanner
                        key={e.id}
                        variant="neg"
                        slim
                        title={`${e.label} couldn’t be made`}
                        role="alert"
                    >
                        Try the export again.
                    </AlertBanner>
                ) : null,
            )}

            <section className="rp-kpis">
                <KpiTile
                    headingLevel="h2"
                    title="Revenue"
                    icon={TrendingUp}
                    paise={kpis.income}
                    caption={`${kpis.months} ${kpis.months === 1 ? 'month' : 'months'}`}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Expenses"
                    icon={Receipt}
                    paise={kpis.expenses}
                    caption={`incl. ${formatInr(kpis.salary)} salaries`}
                />
                <KpiTile
                    headingLevel="h2"
                    title="Other expenses"
                    icon={Coins}
                    paise={kpis.other}
                    caption="Everything except salaries"
                />
                <article className="cf-card cf-card--lime rp-net">
                    <div className="ln-hero-glow" aria-hidden="true" />
                    <h2 className="t-label-strong ln-hero-tag m-0">
                        Net profit
                    </h2>
                    <Money
                        paise={kpis.net}
                        size="lg"
                        countUp
                        className="rp-net-fig"
                    />
                    <span className="t-caption rp-net-cap">
                        {bpsText(kpis.marginBps)} margin
                    </span>
                </article>
            </section>

            <section className="rp-mid">
                <article className="cf-card rp-ratios">
                    <h2 className="cf-dot-title t-title">Ratios</h2>
                    <div className="rp-ratio-grid">
                        <div className="cf-inset cf-stack rp-ratio">
                            <span className="t-caption t-muted">
                                Profit margin
                            </span>
                            <span className="t-money rp-ratio-fig">
                                {bpsText(kpis.marginBps)}
                            </span>
                            <ProgressBar
                                value={clampPct(kpis.marginBps)}
                                variant="lime"
                                thin
                                aria-label="Profit margin"
                            />
                        </div>
                        <div className="cf-inset cf-stack rp-ratio">
                            <span className="t-caption t-muted">
                                Expense ratio
                            </span>
                            <span className="t-money rp-ratio-fig">
                                {bpsText(kpis.expenseRatioBps)}
                            </span>
                            <ProgressBar
                                value={clampPct(kpis.expenseRatioBps)}
                                thin
                                aria-label="Expense ratio"
                            />
                        </div>
                        <div className="cf-inset cf-stack rp-ratio">
                            <span className="t-caption t-muted">
                                Avg monthly revenue
                            </span>
                            <span className="t-money rp-ratio-fig">
                                {formatInr(kpis.avgIncome)}
                            </span>
                            <span className="t-caption t-muted">
                                over {kpis.months}{' '}
                                {kpis.months === 1 ? 'month' : 'months'}
                            </span>
                        </div>
                        <div className="cf-inset cf-stack rp-ratio">
                            <span className="t-caption t-muted">
                                Avg monthly expense
                            </span>
                            <span className="t-money rp-ratio-fig">
                                {formatInr(kpis.avgExpenses)}
                            </span>
                            <span className="t-caption t-muted">
                                incl. salaries and other
                            </span>
                        </div>
                    </div>
                </article>
                <article className="cf-card rp-bars">
                    <div className="cf-kv mb-1">
                        <h2 className="cf-dot-title t-title">Monthly profit</h2>
                        <span className="t-caption t-muted">
                            Highlighted bars are in the selected period
                        </span>
                    </div>
                    <BarChart
                        data={report.bars.map((b) => ({
                            date: `${b.month}-01`,
                            value: b.net,
                        }))}
                        highlightedIndexes={barIndexes}
                        barMaxHeight={150}
                    />
                </article>
            </section>

            <section className="cf-card rp-card" aria-labelledby="rp-months">
                <div className="cf-kv">
                    <h2 id="rp-months" className="cf-dot-title t-title">
                        Month by month
                    </h2>
                    <span className="t-caption t-muted">{report.range}</span>
                </div>
                <DataTable
                    aria-label="Monthly profit and loss"
                    rows={report.months}
                    columns={monthColumns}
                    getRowId={(m) => m.month}
                    minWidth={900}
                    totals={{
                        month: 'Total',
                        income: formatInr(report.totals.income),
                        expenses: formatInr(report.totals.expenses),
                        other: formatInr(report.totals.other),
                        salary: formatInr(report.totals.salary),
                        net: (
                            <span
                                className={
                                    report.totals.net < 0 ? 't-neg' : undefined
                                }
                            >
                                {formatInr(report.totals.net)}
                            </span>
                        ),
                        margin: bpsText(report.totals.marginBps),
                    }}
                    empty={{ title: 'No months in this period yet' }}
                />
            </section>

            <section className="cf-card rp-card" aria-labelledby="rp-partners">
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="rp-partners" className="cf-dot-title t-title">
                        Partner P&amp;L
                    </h2>
                    <span className="t-caption t-muted">
                        {report.range} ·{' '}
                        {company.pooled
                            ? 'Profit due = net profit × equity'
                            : 'Profit due = attributed result + equity share of the rest'}
                    </span>
                </div>
                <DataTable
                    aria-label="Partner profit and loss"
                    rows={report.partners}
                    columns={partnerColumns}
                    getRowId={(p) => String(p.id)}
                    minWidth={1080}
                    totals={{
                        partner: 'Total',
                        equity: bpsText(report.partnerTotals.equityBps).replace(
                            '.0%',
                            '%',
                        ),
                        revenue: formatInr(report.partnerTotals.revenue),
                        expenses: formatInr(report.partnerTotals.expenses),
                        other: formatInr(report.partnerTotals.other),
                        net: formatInr(report.partnerTotals.net),
                        due: formatInr(report.partnerTotals.profitDue),
                        share: report.totals.income === 0 ? '—' : '100%',
                    }}
                    empty={{ title: 'No partners yet' }}
                />
                <p className="t-caption t-muted m-0">
                    Net contribution is revenue a partner brought in, less the
                    expenses and salaries booked to them and their share of
                    shared costs.{' '}
                    {company.pooled
                        ? 'Profit due follows equity only (JV pooled).'
                        : 'Profit due adds their equity share of everything nobody brought in or paid personally.'}
                </p>
            </section>
        </>
    );
}
