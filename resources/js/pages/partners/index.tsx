import { Head, router } from '@inertiajs/react';
import { CalendarDays, ChevronDown, Download, Plus } from 'lucide-react';
import { PageHeader } from '@/components/shell/page-header';
import { initialsFrom, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { ButtonLink, buttonClass } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DonutChart } from '@/components/ui/donut-chart';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { GroupedBars } from '@/components/ui/grouped-bars';
import { Icon } from '@/components/ui/icon';
import { ProgressBar } from '@/components/ui/progress-bar';
import { StatusPill } from '@/components/ui/status-pill';
import { UnitText } from '@/components/ui/money';
import { formatEquity, formatEquityShort } from '@/lib/equity';
import { formatInr, formatInrCompact, formatPercent } from '@/lib/format';
import { formatCenti } from '@/lib/hours';
import { firstName } from '@/lib/team';
import { cn } from '@/lib/utils';
import {
    exportMethod as exportPartners,
    index as partnersIndex,
} from '@/routes/partners';
import { partners as settingsPartners } from '@/routes/settings';

type PartnerRow = {
    id: number;
    name: string;
    role: 'admin' | 'partner';
    colour: number;
    sideId: number | null;
    /** Share within the side (JV) or of the company (Single), bps. */
    equityBps: number;
    overallBps: number;
    revenuePaise: number;
    directPaise: number;
    otherPaise: number;
    salaryPaise: number;
    expensesPaise: number;
    netPaise: number;
    hoursCenti: number;
};

type SideRow = {
    id: number;
    name: string;
    shareBps: number;
    revenuePaise: number;
    expensesPaise: number;
    netPaise: number;
    settlement: {
        status: 'owes' | 'owed' | 'settled';
        amountPaise: number;
    } | null;
};

type Props = {
    company: { name: string; jv: boolean };
    fy: {
        start: number;
        label: string;
        options: { value: number; label: string }[];
        current: boolean;
    };
    partners: PartnerRow[];
    sides: SideRow[];
    abilities: { editEquity: boolean };
};

const dot = (colour: number) => partnerColourVar(colour as PartnerColour);
const pct = (bps: number) => formatEquityShort(bps);

/**
 * Partners (Partners.dc.html, spec §6, D39): comparison, revenue share and equity
 * charts; partner cards (grouped under side bands in a JV, one grid in a Single
 * company); and the detail table for the fiscal year.
 */
export default function Partners({
    company,
    fy,
    partners,
    sides,
    abilities: can,
}: Props) {
    const totalRevenue = partners.reduce((s, p) => s + p.revenuePaise, 0);
    const sum = (key: keyof PartnerRow) =>
        partners.reduce((s, p) => s + (p[key] as number), 0);
    const share = (p: PartnerRow) =>
        totalRevenue > 0 ? (p.revenuePaise / totalRevenue) * 100 : 0;

    const columns: DataTableColumn<PartnerRow>[] = [
        {
            key: 'partner',
            header: 'Partner',
            width: 'minmax(170px,1.4fr)',
            cell: (p) => (
                <span className="cf-row t-label-strong">
                    <span
                        className="cf-avatar cf-avatar--sm"
                        aria-hidden="true"
                    >
                        {initialsFrom(p.name)}
                        <span
                            className="cf-avatar-dot"
                            style={{ background: dot(p.colour) }}
                        />
                    </span>
                    {p.name}
                </span>
            ),
        },
        {
            key: 'equity',
            header: 'Equity',
            width: '80px',
            numeric: true,
            cell: (p) => `${formatEquity(p.overallBps)}%`,
        },
        {
            key: 'revenue',
            header: 'Revenue',
            width: 'minmax(96px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.revenuePaise, { decimals: 'never' }),
        },
        {
            key: 'expenses',
            header: 'Expenses',
            width: 'minmax(96px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.directPaise, { decimals: 'never' }),
        },
        {
            key: 'other',
            header: 'Other',
            width: 'minmax(96px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.otherPaise, { decimals: 'never' }),
        },
        {
            key: 'salary',
            header: 'Salary cost',
            width: 'minmax(96px,1fr)',
            numeric: true,
            cell: (p) => formatInr(p.salaryPaise, { decimals: 'never' }),
        },
        {
            key: 'net',
            header: 'Net contribution',
            width: 'minmax(96px,1fr)',
            numeric: true,
            cell: (p) => (
                <span className={cn('font-medium', p.netPaise < 0 && 't-neg')}>
                    {formatInr(p.netPaise, { decimals: 'never' })}
                </span>
            ),
        },
        {
            key: 'hours',
            header: 'Hours',
            width: '80px',
            numeric: true,
            cell: (p) => formatCenti(p.hoursCenti),
        },
        {
            key: 'share',
            header: 'Share',
            width: '70px',
            numeric: true,
            cell: (p) => formatPercent(share(p)),
        },
    ];

    const card = (p: PartnerRow, side: SideRow | undefined) => (
        <article key={p.id} className="cf-card cf-lift pt-card">
            <div className="cf-row items-start gap-3">
                <span className="cf-avatar cf-avatar--lg" aria-hidden="true">
                    {initialsFrom(p.name)}
                    <span
                        className="cf-avatar-dot"
                        style={{
                            background: dot(p.colour),
                            width: 12,
                            height: 12,
                        }}
                    />
                </span>
                <span className="cf-stack min-w-0 flex-1">
                    <span className="t-label-strong pt-name">{p.name}</span>
                    <span className="t-caption t-muted">
                        {side
                            ? `${pct(p.equityBps)} of ${side.name} · ${formatEquity(p.overallBps)}% overall`
                            : `${formatEquity(p.overallBps)}% of ${company.name}`}
                    </span>
                </span>
                <span
                    className={cn(
                        'cf-pill',
                        p.role === 'admin' && 'cf-pill--graphite',
                    )}
                >
                    {p.role === 'admin' ? 'Admin' : 'Partner'}
                </span>
            </div>
            <div className="pt-stats">
                {[
                    [
                        'Revenue',
                        formatInr(p.revenuePaise, { decimals: 'never' }),
                        false,
                    ],
                    [
                        'Expenses',
                        formatInr(p.expensesPaise, { decimals: 'never' }),
                        false,
                    ],
                    [
                        'Net contribution',
                        formatInr(p.netPaise, { decimals: 'never' }),
                        p.netPaise < 0,
                    ],
                    ['Hours', `${formatCenti(p.hoursCenti)} h`, false],
                ].map(([label, value, negative]) => (
                    <div key={label as string} className="cf-inset cf-stack">
                        <span className="t-caption t-muted">{label}</span>
                        <span className={cn('t-money', negative && 't-neg')}>
                            <UnitText text={value as string} />
                        </span>
                    </div>
                ))}
            </div>
        </article>
    );

    const periodMenu = (
        <DropdownMenu
            aria-label="Fiscal year"
            align="end"
            trigger={
                <button
                    type="button"
                    className="cf-btn cf-btn--muted cf-btn--lg"
                >
                    <Icon icon={CalendarDays} size={18} />
                    {fy.label}
                    <Icon icon={ChevronDown} size={16} />
                </button>
            }
            entries={fy.options
                .slice()
                .reverse()
                .map((o) => ({
                    label: o.label,
                    onSelect: () =>
                        router.get(
                            partnersIndex().url,
                            { fy: o.value },
                            { preserveScroll: true },
                        ),
                }))}
        />
    );

    return (
        <>
            <Head title="Partners" />
            <PageHeader
                kicker={company.jv ? 'Joint venture' : 'Owners'}
                title="Partners"
                crumbs={[{ title: 'Partners' }]}
                actions={
                    <>
                        {periodMenu}
                        {can.editEquity && (
                            <>
                                <ButtonLink
                                    href={settingsPartners().url}
                                    variant="secondary"
                                    size="lg"
                                >
                                    Edit equity
                                </ButtonLink>
                                <ButtonLink
                                    href={settingsPartners().url}
                                    variant="primary"
                                    size="lg"
                                    disc={Plus}
                                >
                                    Add partner
                                </ButtonLink>
                            </>
                        )}
                    </>
                }
            />

            <section className="pt-charts">
                <article className="cf-card pt-compare">
                    <GroupedBars
                        title="Partner comparison"
                        series={['Revenue', 'Expenses', 'Net']}
                        negativeLabel="Net below zero"
                        barMaxHeight={150}
                        groups={partners.map((p) => ({
                            label: firstName(p.name),
                            values: [
                                p.revenuePaise,
                                p.expensesPaise,
                                p.netPaise,
                            ],
                        }))}
                    />
                </article>
                <article className="cf-card pt-side-chart">
                    <h2 className="cf-dot-title t-title">Revenue share</h2>
                    <DonutChart
                        label={`Revenue share: ${partners.map((p) => `${p.name} ${formatPercent(share(p))}`).join(', ')}`}
                        palette="partner"
                        centre={formatInrCompact(totalRevenue)}
                        caption={fy.current ? 'FY to date' : fy.label}
                        slices={partners.map((p) => ({
                            name: firstName(p.name),
                            value: Math.max(0, p.revenuePaise),
                            color: dot(p.colour),
                        }))}
                    />
                </article>
                <article className="cf-card pt-side-chart flex flex-col gap-3">
                    <h2 className="cf-dot-title t-title">Equity split</h2>
                    {partners.map((p) => (
                        <div key={p.id} className="cf-stack gap-1.5">
                            <div className="cf-kv t-body">
                                <span>{p.name}</span>
                                <span className="t-money-sm">
                                    {formatEquity(p.overallBps)}%
                                </span>
                            </div>
                            <ProgressBar
                                value={p.overallBps / 100}
                                aria-label={`${p.name} equity`}
                                aria-valuetext={`${formatEquity(p.overallBps)}%`}
                                fill={dot(p.colour)}
                            />
                        </div>
                    ))}
                    {company.jv && (
                        <span className="t-caption t-muted">
                            Side share × % within side
                        </span>
                    )}
                </article>
            </section>

            {company.jv ? (
                sides.map((side) => {
                    const mine = partners.filter((p) => p.sideId === side.id);

                    return (
                        <section
                            key={side.id}
                            aria-label={side.name}
                            className="flex flex-col gap-3"
                        >
                            <div className="pt-band">
                                <span className="cf-switcher-ini pt-band-ini">
                                    {initialsFrom(side.name)}
                                </span>
                                <span className="cf-stack pt-band-title">
                                    <span className="pt-band-name">
                                        {side.name}
                                    </span>
                                    <span className="t-caption pt-band-muted">
                                        {pct(side.shareBps)} share ·{' '}
                                        {mine.length}{' '}
                                        {mine.length === 1
                                            ? 'partner'
                                            : 'partners'}
                                    </span>
                                </span>
                                {side.settlement &&
                                    side.settlement.status !== 'settled' && (
                                        <StatusPill
                                            status={side.settlement.status}
                                        >
                                            {side.settlement.status === 'owes'
                                                ? 'OWES'
                                                : 'OWED'}{' '}
                                            <span className="t-money-sm">
                                                {formatInr(
                                                    side.settlement.amountPaise,
                                                    { decimals: 'never' },
                                                )}
                                            </span>
                                        </StatusPill>
                                    )}
                                {side.settlement?.status === 'settled' && (
                                    <StatusPill status="settled" />
                                )}
                                {[
                                    ['Revenue', side.revenuePaise],
                                    ['Expenses', side.expensesPaise],
                                    ['Net', side.netPaise],
                                ].map(([label, value]) => (
                                    <span key={label} className="cf-stack">
                                        <span className="t-caption pt-band-muted">
                                            {label}
                                        </span>
                                        <span className="t-money">
                                            {formatInr(value as number, {
                                                decimals: 'never',
                                            })}
                                        </span>
                                    </span>
                                ))}
                            </div>
                            <div className="pt-grid">
                                {mine.map((p) => card(p, side))}
                            </div>
                        </section>
                    );
                })
            ) : (
                <section aria-label="Partners" className="pt-grid">
                    {partners.map((p) => card(p, undefined))}
                </section>
            )}

            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="pd-h"
            >
                <div className="cf-kv">
                    <h2 id="pd-h" className="cf-dot-title t-title">
                        Partner detail · {fy.label}
                    </h2>
                    {/* A plain link: the CSV downloads, no Inertia visit (P3). */}
                    <a
                        href={exportPartners({ query: { fy: fy.start } }).url}
                        className={buttonClass({
                            variant: 'secondary',
                            size: 'sm',
                        })}
                    >
                        <Icon icon={Download} size={16} />
                        Export
                    </a>
                </div>
                <DataTable
                    aria-label="Partner detail"
                    rows={partners}
                    columns={columns}
                    getRowId={(p) => String(p.id)}
                    minWidth={1080}
                    totals={{
                        partner: 'Total',
                        equity: '100.00%',
                        revenue: formatInr(sum('revenuePaise'), {
                            decimals: 'never',
                        }),
                        expenses: formatInr(sum('directPaise'), {
                            decimals: 'never',
                        }),
                        other: formatInr(sum('otherPaise'), {
                            decimals: 'never',
                        }),
                        salary: formatInr(sum('salaryPaise'), {
                            decimals: 'never',
                        }),
                        net: formatInr(sum('netPaise'), { decimals: 'never' }),
                        hours: formatCenti(sum('hoursCenti')),
                        share: totalRevenue > 0 ? '100%' : '—',
                    }}
                    empty={{ title: 'No partners yet' }}
                />
            </section>
        </>
    );
}
