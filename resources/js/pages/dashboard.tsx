import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    ArrowDownRight,
    Check,
    CircleCheck,
    Clock,
    Coins,
    ChartPie,
    LayoutGrid,
    Plus,
    Receipt,
    RefreshCw,
    Scale,
    TrendingUp,
    Wallet,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { BalanceStrip } from '@/components/finance/balance-strip';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import { RejectDialog } from '@/components/finance/reject-dialog';
import { PeriodMenu } from '@/components/reports/period-menu';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner, AlertMoney } from '@/components/ui/alert-banner';
import { AreaChart } from '@/components/ui/area-chart';
import { Avatar, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { BarChart } from '@/components/ui/bar-chart';
import { Button, ButtonLink, buttonClass } from '@/components/ui/button';
import { DonutChart } from '@/components/ui/donut-chart';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { KpiTile } from '@/components/ui/kpi-tile';
import { StatusPill } from '@/components/ui/status-pill';
import { TrendChip } from '@/components/ui/trend-chip';
import { useCountUp } from '@/hooks/use-count-up';
import { formatDate, formatInr, formatInrCompact } from '@/lib/format';
import { firstName } from '@/lib/team';
import { dashboard } from '@/routes';
import { create as createCompany } from '@/routes/companies';
import {
    approve as approveExpense,
    index as expensesIndex,
    reject as rejectExpense,
} from '@/routes/expenses';
import { index as incomeIndex } from '@/routes/income';
import { index as settlementsIndex } from '@/routes/settlements';
import type {
    DashboardData,
    DashboardVaultPanel,
    PendingApproval,
    PeriodQuery,
    ReportPeriodProps,
    Trend,
} from '@/types/reports';

type Props = {
    company: { name: string; jv: boolean };
    period: ReportPeriodProps;
    query: PeriodQuery;
    /** Null when the figures couldn't be read: the error state, never zeros. */
    dashboard: DashboardData | null;
    approvals: { count: number; totalPaise: number; rows: PendingApproval[] };
    vault: DashboardVaultPanel;
    abilities: {
        createCompany: boolean;
        approve: boolean;
        addIncome: boolean;
        addExpense: boolean;
        expenses: boolean;
        settlements: boolean;
    };
};

/** Percent change on the previous equal period; null when there's nothing to compare. */
const change = (t: Trend): number | null =>
    t.previous === 0
        ? null
        : Math.round(((t.paise - t.previous) / Math.abs(t.previous)) * 1000) /
          10;

const monthName = (iso: string) =>
    new Date(`${iso}-01T00:00:00`).toLocaleString('en-IN', { month: 'long' });

/** Seconds of loading before the "Still loading" note (StateLoading.dc.html). */
const SLOW_MS = 4000;

/**
 * Dashboard (Main.dc.html, spec §5): the period control, the balance hero with its formula
 * strip (D58), KPIs with trends against the previous equal period, the JV settlement
 * alert, revenue vs expenses, profit by month, revenue by partner, expenses by category,
 * pending approvals with inline approve / reject, and the vault panel once the vault
 * exists (D64). Loading, empty and error states follow StateLoading / StateEmpty.
 */
export default function Dashboard(props: Props) {
    const { auth } = usePage().props;
    const { period, dashboard: data, abilities } = props;
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [slow, setSlow] = useState(false);

    useEffect(() => {
        if (!loading) {
            return;
        }

        const timer = window.setTimeout(() => setSlow(true), SLOW_MS);

        return () => {
            window.clearTimeout(timer);
            setSlow(false);
        };
    }, [loading]);

    const go = (query: PeriodQuery) =>
        router.get(dashboard().url, cleanQuery(query), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onStart: () => {
                setFailed(false);
                setLoading(true);
            },
            onFinish: () => setLoading(false),
            onHttpException: () => {
                setFailed(true);

                return false;
            },
            onNetworkError: () => {
                setFailed(true);

                return false;
            },
        });

    const retry = () => go(props.query);

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader
                kicker={`Hi ${firstName(auth.user.name)},`}
                title="Dashboard"
                actions={
                    <>
                        <FyMenu
                            fy={period.fy}
                            onPick={(start) => go({ fy: start })}
                        />
                        <PeriodMenu period={period} onChange={go} />
                        {abilities.createCompany && (
                            <ButtonLink
                                href={createCompany().url}
                                variant="primary"
                                size="lg"
                                disc={Plus}
                            >
                                New company
                            </ButtonLink>
                        )}
                    </>
                }
            />

            {loading ? (
                <DashboardSkeleton slow={slow} company={props.company.name} />
            ) : failed || data === null ? (
                <ErrorState
                    title="The dashboard couldn’t load"
                    description="Nothing you’ve saved is affected. Try again, or open Reports for the same figures."
                    actions={
                        <Button
                            variant="secondary"
                            icon={RefreshCw}
                            onClick={retry}
                        >
                            Try again
                        </Button>
                    }
                />
            ) : data.empty ? (
                <EmptyDashboard {...props} />
            ) : (
                <Figures {...props} data={data} />
            )}
        </>
    );
}

function Figures({
    company,
    period,
    data,
    approvals,
    vault,
    abilities,
}: Props & { data: DashboardData }) {
    const [selected, setSelected] = useState<number | null>(data.selectedMonth);
    const { kpis, balance } = data;
    const balanceShown = useCountUp(balance.currentPaise);
    const comparedTo = period.comparedTo;
    const fyCaption =
        period.preset === 'fy' ? `${period.fy.label} to date` : period.label;
    const months = data.months.map((m) => ({
        date: `${m.month}-01`,
        value: m.income,
        comparison: m.expenses,
    }));
    const settlement = data.settlement;
    const first = settlement?.transfers[0];

    const trend = (t: Trend, tone: 'auto' | 'neutral' = 'auto') => {
        const pct = change(t);

        return pct === null ? undefined : (
            <TrendChip
                percent={pct}
                comparedTo={comparedTo}
                tone={tone === 'neutral' ? 'neutral' : undefined}
            />
        );
    };

    return (
        <>
            <section className="db-top">
                <article className="cf-card cf-card--lime db-hero cf-rise">
                    <div className="db-hero-glow" aria-hidden="true" />
                    <div className="db-hero-row">
                        <span className="t-label-strong ln-hero-tag db-hero-tag">
                            <Icon icon={Wallet} size={18} />
                            Current balance
                        </span>
                        <span className="t-caption ln-hero-tag">
                            {period.fy.label} · as of{' '}
                            {formatDate(balance.asOf).replace(/ \d{4}$/u, '')}
                        </span>
                    </div>
                    <div className="db-hero-mid">
                        <div className="ln-hero-figure">
                            <span className="t-money-unit ln-hero-unit">
                                {balance.currentPaise < 0 ? '−₹' : '₹'}
                            </span>
                            <span className="t-figure-hero">
                                {formatInr(Math.abs(balanceShown), {
                                    symbol: false,
                                })}
                            </span>
                        </div>
                        {balance.latestMonth && (
                            <span className="t-caption ln-hero-tag db-hero-chip">
                                <Icon
                                    icon={
                                        balance.latestMonth.netPaise < 0
                                            ? ArrowDownRight
                                            : ArrowUpRight
                                    }
                                    size={14}
                                    strokeWidth={1.75}
                                />
                                {formatInr(
                                    Math.abs(balance.latestMonth.netPaise),
                                )}{' '}
                                net{' '}
                                {balance.latestMonth.netPaise < 0
                                    ? 'loss'
                                    : 'profit'}{' '}
                                in {monthName(balance.latestMonth.month)}
                            </span>
                        )}
                    </div>
                    <BalanceStrip terms={balance} />
                </article>

                <div className="db-kpis">
                    <KpiTile
                        className="cf-lift"
                        headingLevel="h2"
                        title="Total income"
                        icon={TrendingUp}
                        paise={kpis.income.paise}
                        aside={trend(kpis.income)}
                        caption={fyCaption}
                    />
                    <KpiTile
                        className="cf-lift"
                        headingLevel="h2"
                        title="Total expenses"
                        icon={Receipt}
                        paise={kpis.expenses.paise}
                        aside={trend(kpis.expenses, 'neutral')}
                        caption={`Includes ${formatInr(kpis.expenses.salary)} in salaries`}
                    />
                    <KpiTile
                        className="cf-lift"
                        headingLevel="h2"
                        title="Other expenses"
                        icon={Coins}
                        paise={kpis.other.paise}
                        aside={trend(kpis.other, 'neutral')}
                        caption="Everything except salaries"
                    />
                    <KpiTile
                        className="cf-lift"
                        headingLevel="h2"
                        title="Net profit"
                        icon={ChartPie}
                        paise={kpis.net.paise}
                        aside={trend(kpis.net)}
                        caption={`Income less expenses · ${fyCaption}`}
                    />
                    <KpiTile
                        className="cf-lift"
                        headingLevel="h2"
                        title="Total hours"
                        icon={Clock}
                        hours={kpis.hours.centi / 100}
                        aside={trend(
                            {
                                paise: kpis.hours.centi,
                                previous: kpis.hours.previous,
                            },
                            'neutral',
                        )}
                        caption={`${kpis.hours.members} team ${kpis.hours.members === 1 ? 'member' : 'members'} · ${fyCaption}`}
                    />
                    <KpiTile
                        className="cf-lift"
                        headingLevel="h2"
                        title="Pending approval"
                        icon={Check}
                        paise={kpis.pending.paise}
                        aside={
                            <span
                                className={`cf-pill ${kpis.pending.count > 0 ? 'cf-pill--warn' : 'cf-pill--pos'}`}
                            >
                                {kpis.pending.count > 0
                                    ? `${kpis.pending.count} ${kpis.pending.count === 1 ? 'expense' : 'expenses'}`
                                    : 'Cleared'}
                            </span>
                        }
                        caption={
                            abilities.expenses
                                ? 'Review in Expenses'
                                : 'Waiting for a partner'
                        }
                        captionHref={
                            abilities.expenses
                                ? expensesIndex({
                                      query: { status: 'pending' },
                                  }).url
                                : undefined
                        }
                    />
                </div>
            </section>

            {settlement && first && (
                <AlertBanner
                    variant="graphite"
                    icon={Scale}
                    aria-label="Settlement alert"
                    title={
                        settlement.transfers.length === 1 ? (
                            <>
                                {first.fromName} owes {first.toName}{' '}
                                <AlertMoney paise={first.amountPaise} />
                            </>
                        ) : (
                            `${settlement.transfers.length} transfers settle all ${settlement.sides} sides`
                        )
                    }
                    badge={<StatusPill status="owes" />}
                    action={
                        abilities.settlements ? (
                            <ButtonLink
                                href={
                                    settlementsIndex({
                                        query: { fy: period.fy.start },
                                    }).url
                                }
                                variant="onDark"
                                size="lg"
                                disc={ArrowRight}
                            >
                                Resolve
                            </ButtonLink>
                        ) : undefined
                    }
                >
                    {company.name} ·{' '}
                    {settlement.sides === 2
                        ? `${settlement.shares} sides`
                        : `${settlement.sides} sides`}
                    {settlement.transfers.length === 1 && (
                        <>
                            {' '}
                            · {first.fromName} is entitled to{' '}
                            <span className="t-money-sm">
                                {formatInr(settlement.fromEntitledPaise)}
                            </span>{' '}
                            and holds{' '}
                            <span className="t-money-sm">
                                {formatInr(settlement.fromHoldsPaise)}
                            </span>
                        </>
                    )}
                </AlertBanner>
            )}

            <section className="db-charts">
                <article className="cf-card db-area cf-rise">
                    <AreaChart
                        title="Revenue vs expenses"
                        data={months}
                        height={220}
                        aria-label={`Monthly revenue and expenses, ${period.label}.`}
                    />
                </article>
                <article className="cf-card db-bars cf-rise">
                    <BarChart
                        title="Profit by month"
                        data={data.months.map((m) => ({
                            date: `${m.month}-01`,
                            value: m.net,
                        }))}
                        selectedIndex={selected}
                        onSelect={setSelected}
                        barMaxHeight={150}
                    />
                </article>
            </section>

            <section className="db-bottom">
                <article className="cf-card db-panel cf-lift">
                    <h2 className="cf-dot-title t-title">Revenue by partner</h2>
                    <DonutChart
                        label="Revenue by partner"
                        palette="partner"
                        centre={formatInrCompact(kpis.income.paise)}
                        caption={
                            period.preset === 'fy' ? 'FY to date' : period.label
                        }
                        slices={data.revenueByPartner.map((s) => ({
                            name: s.name,
                            value: Math.max(0, s.paise),
                            color:
                                s.colour === null
                                    ? 'var(--ink-faint)'
                                    : partnerColourVar(
                                          s.colour as PartnerColour,
                                      ),
                        }))}
                    />
                </article>
                <article className="cf-card db-panel cf-lift">
                    <h2 className="cf-dot-title t-title">
                        Expenses by category
                    </h2>
                    <DonutChart
                        label="Expenses by category"
                        palette="brand"
                        centre={formatInrCompact(kpis.expenses.paise)}
                        caption={
                            period.preset === 'fy' ? 'FY to date' : period.label
                        }
                        slices={data.expensesByCategory.map((s) => ({
                            name: s.name,
                            value: s.paise,
                        }))}
                    />
                </article>
                <Approvals approvals={approvals} abilities={abilities} />
                {vault && <VaultPanel vault={vault} />}
            </section>
        </>
    );
}

function Approvals({
    approvals,
    abilities,
}: Pick<Props, 'approvals' | 'abilities'>) {
    const [busy, setBusy] = useState<number | null>(null);
    const [rejecting, setRejecting] = useState<PendingApproval | null>(null);

    const approve = (row: PendingApproval) => {
        setBusy(row.id);
        router.post(
            approveExpense(row.id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        );
    };

    return (
        <article className="cf-card db-panel">
            <div className="cf-kv">
                <h2 className="cf-dot-title t-title">Pending approvals</h2>
                <span className="cf-badge db-count">{approvals.count}</span>
            </div>
            {approvals.rows.length === 0 && approvals.count > 0 ? (
                <p className="t-body t-muted">
                    {approvals.count === 1
                        ? '1 expense is'
                        : `${approvals.count} expenses are`}{' '}
                    waiting for approval ({formatInr(approvals.totalPaise)}).
                </p>
            ) : approvals.rows.length === 0 ? (
                <div className="db-caught-up">
                    <span className="db-caught-ic">
                        <Icon icon={Check} size={18} strokeWidth={2} />
                    </span>
                    <span className="t-label-strong">All caught up</span>
                    <span className="t-caption t-muted">
                        Nothing is waiting for approval.
                    </span>
                </div>
            ) : (
                <ul className="db-list">
                    {approvals.rows.map((row) => (
                        <li key={row.id} className="db-item">
                            <Avatar
                                name={row.submitter ?? row.description}
                                partner={
                                    row.colour
                                        ? (row.colour as PartnerColour)
                                        : undefined
                                }
                                size="sm"
                                label={false}
                            />
                            <div className="db-item-text">
                                <div className="t-body font-medium">
                                    {row.description}
                                </div>
                                <div className="t-caption t-muted">
                                    Expense · {formatInr(row.amountPaise)}
                                    {row.submitter
                                        ? ` · ${row.submitter}`
                                        : ''}{' '}
                                    · {formatDate(row.date)}
                                </div>
                            </div>
                            {abilities.approve && row.canDecide && (
                                <>
                                    <IconButton
                                        icon={Check}
                                        aria-label={`Approve ${row.description}`}
                                        title="Approve"
                                        variant="pos"
                                        size="sm"
                                        disabled={busy !== null}
                                        onClick={() => approve(row)}
                                    />
                                    <IconButton
                                        icon={X}
                                        aria-label={`Reject ${row.description}`}
                                        title="Reject"
                                        variant="neg"
                                        size="sm"
                                        disabled={busy !== null}
                                        onClick={() => setRejecting(row)}
                                    />
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {abilities.expenses && approvals.count > 0 && (
                <Link
                    href={expensesIndex({ query: { status: 'pending' } }).url}
                    className="t-caption db-more font-medium"
                >
                    Review in Expenses
                </Link>
            )}
            <RejectDialog
                open={rejecting !== null}
                onOpenChange={(open) => !open && setRejecting(null)}
                what={rejecting?.description ?? ''}
                busy={busy !== null}
                onReject={(reason) => {
                    if (!rejecting) {
                        return;
                    }

                    setBusy(rejecting.id);
                    router.post(
                        rejectExpense(rejecting.id).url,
                        { reason },
                        {
                            preserveScroll: true,
                            onSuccess: () => setRejecting(null),
                            onFinish: () => setBusy(null),
                        },
                    );
                }}
            />
        </article>
    );
}

/** "Credentials expiring soon" (spec §5): rendered only once the vault fills the prop (D64). */
function VaultPanel({ vault }: { vault: NonNullable<DashboardVaultPanel> }) {
    return (
        <article className="cf-card db-panel">
            <div className="cf-kv">
                <h2 className="cf-dot-title t-title">
                    Credentials expiring soon
                </h2>
                <Link
                    href={vault.href}
                    className="cf-iconbtn"
                    aria-label="Open the credentials vault"
                >
                    <Icon icon={ArrowUpRight} size={16} strokeWidth={1.75} />
                </Link>
            </div>
            {vault.items.length === 0 ? (
                <p className="t-body t-muted m-0">
                    Nothing expires in the next 30 days.
                </p>
            ) : (
                <ul className="db-list">
                    {vault.items.map((item) => (
                        <li key={item.id} className="db-item">
                            <Avatar
                                name={item.name}
                                initials={item.initials}
                                size="sm"
                                tile
                                graphite
                                label={false}
                            />
                            <div className="db-item-text">
                                <div className="t-body font-medium">
                                    {item.name}
                                </div>
                                <div className="t-caption t-muted">
                                    Expires {formatDate(item.expiresOn)}
                                </div>
                            </div>
                            <span
                                className={`cf-pill ${item.days <= 14 ? 'cf-pill--neg' : 'cf-pill--warn'}`}
                            >
                                {item.days} {item.days === 1 ? 'day' : 'days'}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <Link href={vault.href} className="t-caption db-more font-medium">
                {vault.healthScore !== null
                    ? `Vault health ${vault.healthScore}/100 · `
                    : ''}
                open the vault
            </Link>
        </article>
    );
}

function EmptyDashboard({ company, abilities }: Props) {
    return (
        <EmptyState
            icon={LayoutGrid}
            headingLevel={2}
            title="No figures yet"
            description={`Add the first payment ${company.name} received or the first expense. The balance, monthly trend and each partner’s share fill in from there.`}
            className="db-empty"
            actions={
                <>
                    {abilities.addIncome && (
                        <Link
                            href={incomeIndex().url}
                            className={buttonClass({
                                variant: 'primary',
                                size: 'lg',
                            })}
                        >
                            Add income
                        </Link>
                    )}
                    {abilities.addExpense && (
                        <Link
                            href={expensesIndex().url}
                            className={buttonClass({
                                variant: 'secondary',
                                size: 'lg',
                            })}
                        >
                            Add an expense
                        </Link>
                    )}
                </>
            }
        />
    );
}

/** StateLoading.dc.html, shown while another period loads. */
function DashboardSkeleton({
    slow,
    company,
}: {
    slow: boolean;
    company: string;
}) {
    return (
        <div aria-busy="true" className="db-skeleton">
            <span className="cf-sr" role="status">
                Loading the dashboard
            </span>
            <section className="db-skel-tiles" aria-hidden="true">
                {['55%', '40%', '62%', '48%'].map((w, i) => (
                    <div key={i} className="cf-kpi" style={{ gap: 14 }}>
                        <div className="cf-row" style={{ gap: 10 }}>
                            <span
                                className="cf-skel cf-skel--circle"
                                style={{ width: 32, height: 32 }}
                            />
                            <span
                                className="cf-skel cf-skel--line"
                                style={{ width: w }}
                            />
                        </div>
                        <span
                            className="cf-skel"
                            style={{
                                width: '70%',
                                height: 36,
                                borderRadius: 10,
                            }}
                        />
                        <span
                            className="cf-skel cf-skel--line"
                            style={{ width: '40%' }}
                        />
                    </div>
                ))}
            </section>
            <section className="db-skel-charts" aria-hidden="true">
                <div className="cf-card db-skel-card">
                    <div className="cf-kv">
                        <span
                            className="cf-skel cf-skel--title"
                            style={{ width: 180 }}
                        />
                        <span
                            className="cf-skel"
                            style={{
                                width: 160,
                                height: 32,
                                borderRadius: 9999,
                            }}
                        />
                    </div>
                    <div className="db-skel-bars">
                        {['40%', '62%', '55%', '78%', '48%', '86%', '92%'].map(
                            (h, i) => (
                                <span
                                    key={i}
                                    className="cf-skel"
                                    style={{
                                        flex: 1,
                                        height: h,
                                        borderRadius: '12px 12px 0 0',
                                    }}
                                />
                            ),
                        )}
                    </div>
                </div>
                <div className="cf-card db-skel-card">
                    <span
                        className="cf-skel cf-skel--title"
                        style={{ width: 140 }}
                    />
                    {['60%', '45%', '70%', '52%'].map((w, i) => (
                        <div key={i} className="cf-row" style={{ gap: 12 }}>
                            <span
                                className="cf-skel cf-skel--circle"
                                style={{ width: 36, height: 36 }}
                            />
                            <span
                                className="cf-stack"
                                style={{ flex: 1, gap: 8 }}
                            >
                                <span
                                    className="cf-skel cf-skel--line"
                                    style={{ width: w }}
                                />
                                <span
                                    className="cf-skel"
                                    style={{
                                        width: '100%',
                                        height: 8,
                                        borderRadius: 9999,
                                    }}
                                />
                            </span>
                        </div>
                    ))}
                </div>
            </section>
            {slow && (
                <AlertBanner
                    variant="info"
                    slim
                    icon={CircleCheck}
                    title={`Still loading ${company}`}
                    role="status"
                >
                    Big companies can take a few seconds the first time.
                    Everything you’ve saved is safe.
                </AlertBanner>
            )}
        </div>
    );
}
