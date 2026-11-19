import { Head, Link, router } from '@inertiajs/react';
import {
    KeyRound,
    Pencil,
    Trash2,
    UserRoundCheck,
    UserRoundX,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { MemberConfirm } from '@/components/team/member-confirm';
import type { MemberConfirmState } from '@/components/team/member-confirm';
import { MemberModal } from '@/components/team/member-modal';
import { initialsFrom, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { BarChart } from '@/components/ui/bar-chart';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { StatusPill } from '@/components/ui/status-pill';
import { UnitText } from '@/components/ui/money';
import { LevelPill } from '@/components/vault/home/vault-bits';
import { formatDate, formatInr, formatMonth } from '@/lib/format';
import { formatCenti } from '@/lib/hours';
import { firstName, monthLong, rateLabel } from '@/lib/team';
import { cn } from '@/lib/utils';
import { index as teamIndex } from '@/routes/team';
import { index as vaultIndex } from '@/routes/vault';
import type {
    CostType,
    PartnerOption,
    TeamCan,
    TeamMember,
    TeamVaultAccess,
    UserOption,
} from '@/types/team';

type Rate = {
    id: number;
    from: string;
    costType: CostType;
    ratePaise: number;
    by: string | null;
    current: boolean;
};

type Month = {
    month: string;
    hoursCenti: number;
    billableCenti: number;
    earningsPaise: number;
};

type Salary = { date: string; memo: string | null; amountPaise: number };

type Props = {
    company: { name: string; workspace: string };
    member: TeamMember & {
        email: string | null;
        user: { name: string; email: string } | null;
        deactivatedOn: string | null;
        createdAt: string | null;
    };
    partners: PartnerOption[];
    rates: Rate[];
    months: Month[];
    salaries: Salary[];
    abilities: TeamCan;
    users: UserOption[];
    vaultAccess: TeamVaultAccess;
};

const hoursText = (centi: number) => `${formatCenti(centi, 0)} h`;

/**
 * Member profile (`/team/{member}`, derived from Team.dc.html cards, a Timesheets-style
 * table and a BarChart; spec §7): details, rate history, monthly hours, salary
 * history, and the vault items they can open (with the vault, Phase 7).
 */
export default function TeamShow({
    company,
    member,
    partners,
    rates,
    months,
    salaries,
    abilities: can,
    users,
    vaultAccess,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [confirm, setConfirm] = useState<MemberConfirmState>(null);
    const partner = partners.find((p) => p.id === member.partnerId);
    const dot = partner
        ? partnerColourVar(partner.colour as PartnerColour)
        : 'var(--line-strong)';
    const thisMonth = months.at(-1);

    const monthColumns: DataTableColumn<Month>[] = [
        {
            key: 'month',
            header: 'Month',
            cell: (m) => <span className="t-body">{formatMonth(m.month)}</span>,
        },
        {
            key: 'hours',
            header: 'Hours',
            numeric: true,
            cell: (m) => formatCenti(m.hoursCenti),
        },
        {
            key: 'billable',
            header: 'Billable',
            numeric: true,
            cell: (m) => formatCenti(m.billableCenti),
        },
        {
            key: 'earnings',
            header: 'Earnings',
            numeric: true,
            cell: (m) => formatInr(m.earningsPaise, { decimals: 'never' }),
        },
    ];

    const rateColumns: DataTableColumn<Rate>[] = [
        {
            key: 'from',
            header: 'From',
            cell: (r) => <span className="t-body">{formatDate(r.from)}</span>,
        },
        {
            key: 'type',
            header: 'Cost type',
            cell: (r) => (
                <span className="t-body">
                    {r.costType === 'hourly' ? 'Hourly' : 'Fixed monthly'}
                </span>
            ),
        },
        {
            key: 'rate',
            header: 'Rate',
            numeric: true,
            cell: (r) => rateLabel(r),
        },
        {
            key: 'by',
            header: 'Set by',
            cell: (r) => (
                <span className="t-body t-muted">{r.by ?? 'When added'}</span>
            ),
        },
        {
            key: 'state',
            header: <span className="cf-sr">In effect</span>,
            width: '110px',
            skeleton: 'pill',
            cell: (r) =>
                r.current ? (
                    <StatusPill tone="pos" size="sm">
                        Current
                    </StatusPill>
                ) : null,
        },
    ];

    const salaryColumns: DataTableColumn<Salary>[] = [
        {
            key: 'date',
            header: 'Date',
            width: '140px',
            cell: (s) => (
                <span className="t-body t-muted">{formatDate(s.date)}</span>
            ),
        },
        {
            key: 'memo',
            header: 'Description',
            cell: (s) => <span className="t-body">{s.memo}</span>,
        },
        {
            key: 'auto',
            header: 'Type',
            width: '100px',
            skeleton: 'pill',
            cell: () => <StatusPill status="auto" size="sm" />,
        },
        {
            key: 'amount',
            header: 'Amount',
            width: '130px',
            numeric: true,
            cell: (s) => formatInr(s.amountPaise),
        },
    ];

    return (
        <>
            <Head title={member.name} />
            <PageHeader
                kicker="Team"
                title={member.name}
                crumbs={[
                    { title: 'Team', href: teamIndex().url },
                    { title: member.name },
                ]}
                actions={
                    can.manage && (
                        <>
                            {!member.hasHistory && (
                                <Button
                                    variant="ghost"
                                    size="lg"
                                    icon={Trash2}
                                    onClick={() =>
                                        setConfirm({ kind: 'delete', member })
                                    }
                                >
                                    Delete
                                </Button>
                            )}
                            <Button
                                variant="secondary"
                                size="lg"
                                icon={
                                    member.active ? UserRoundX : UserRoundCheck
                                }
                                onClick={() =>
                                    setConfirm({ kind: 'status', member })
                                }
                            >
                                {member.active ? 'Deactivate' : 'Reactivate'}
                            </Button>
                            <Button
                                variant="primary"
                                size="lg"
                                disc={Pencil}
                                onClick={() => setEditOpen(true)}
                            >
                                Edit member
                            </Button>
                        </>
                    )
                }
            />

            <div className="profile-row">
                <section
                    className={cn(
                        'cf-card profile-main flex flex-col gap-3.5',
                        !member.active && 'opacity-90',
                    )}
                    aria-label="Details"
                >
                    <div className="cf-row items-start gap-3">
                        <span
                            className="cf-avatar cf-avatar--xl"
                            aria-hidden="true"
                        >
                            {initialsFrom(member.name)}
                            <span
                                className="cf-avatar-dot"
                                style={{
                                    background: dot,
                                    width: 14,
                                    height: 14,
                                }}
                            />
                        </span>
                        <span className="cf-stack min-w-0 flex-1">
                            <span className="t-heading">{member.name}</span>
                            <span className="t-caption t-muted">
                                {member.designation ?? 'Team member'}
                            </span>
                        </span>
                        <span
                            className={cn(
                                'cf-pill',
                                member.active && 'cf-pill--pos',
                            )}
                        >
                            {member.active ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <span className="cf-pill cf-pill--outline self-start">
                        <span
                            className="cf-swatch"
                            style={{ background: dot, width: 8, height: 8 }}
                        />
                        Bills to {partner?.name}
                    </span>
                    <dl className="profile-facts">
                        <div className="cf-inset cf-stack">
                            <dt className="t-caption t-muted">
                                {member.costType === 'hourly'
                                    ? 'Rate'
                                    : 'Monthly cost'}
                            </dt>
                            <dd className="t-money">{rateLabel(member)}</dd>
                        </div>
                        <div className="cf-inset cf-stack">
                            <dt className="t-caption t-muted">
                                Billable in{' '}
                                {thisMonth
                                    ? monthLong(thisMonth.month)
                                    : 'this month'}
                            </dt>
                            <dd className="t-money">
                                <UnitText
                                    text={hoursText(
                                        thisMonth?.billableCenti ?? 0,
                                    )}
                                />
                            </dd>
                        </div>
                        <div className="cf-inset cf-stack">
                            <dt className="t-caption t-muted">
                                Earnings this month
                            </dt>
                            <dd className="t-money">
                                {formatInr(thisMonth?.earningsPaise ?? 0, {
                                    decimals: 'never',
                                })}
                            </dd>
                        </div>
                        <div className="cf-inset cf-stack">
                            <dt className="t-caption t-muted">User account</dt>
                            <dd className="t-body">
                                {member.user ? member.user.email : 'Not linked'}
                            </dd>
                        </div>
                    </dl>
                    {member.deactivatedOn && (
                        <p className="t-caption t-muted m-0">
                            Deactivated on {formatDate(member.deactivatedOn)}.
                        </p>
                    )}
                </section>

                <section className="cf-card profile-side">
                    <BarChart
                        title="Billable hours by month"
                        metric="billable hours"
                        format={hoursText}
                        data={months.map((m) => ({
                            date: m.month,
                            value: m.billableCenti,
                        }))}
                        selectedIndex={months.length - 1}
                        barMaxHeight={120}
                        emptyTitle="No hours yet"
                        emptyText="Bars appear once they log hours."
                    />
                </section>
            </div>

            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="mh-h"
            >
                <h2 id="mh-h" className="cf-dot-title t-title">
                    Monthly hours
                </h2>
                <DataTable
                    aria-label="Monthly hours"
                    rows={[...months].reverse()}
                    columns={monthColumns}
                    getRowId={(m) => m.month}
                    minWidth={520}
                    empty={{ title: 'No hours this year' }}
                />
            </section>

            <div className="profile-row">
                <section
                    className="cf-card profile-main flex flex-col gap-3.5"
                    aria-labelledby="rh-h"
                >
                    <h2 id="rh-h" className="cf-dot-title t-title">
                        Rate history
                    </h2>
                    <DataTable
                        aria-label="Rate history"
                        rows={rates}
                        columns={rateColumns}
                        getRowId={(r) => String(r.id)}
                        minWidth={560}
                    />
                </section>
                <section
                    className="cf-card profile-side flex flex-col gap-3.5"
                    aria-labelledby="va-h"
                >
                    <h2 id="va-h" className="cf-dot-title t-title">
                        Vault access
                    </h2>
                    <MemberVaultAccess
                        name={firstName(member.name)}
                        access={vaultAccess}
                    />
                </section>
            </div>

            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="sh-h"
            >
                <h2 id="sh-h" className="cf-dot-title t-title">
                    Salary history
                </h2>
                <DataTable
                    aria-label="Salary history"
                    rows={salaries}
                    columns={salaryColumns}
                    getRowId={(s) => `${s.date}${s.memo}`}
                    minWidth={560}
                    empty={{ title: 'No salary posted yet' }}
                />
            </section>

            {can.manage && (
                <MemberModal
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    member={member}
                    partners={partners}
                    users={users}
                    companyName={company.name}
                    workspaceName={company.workspace}
                    canEditRates={can.editRates}
                />
            )}

            <MemberConfirm
                confirm={confirm}
                onClose={() => setConfirm(null)}
                afterDelete={() => router.visit(teamIndex().url)}
            />
        </>
    );
}

/** Read-only list of the vault items this member can open (with the vault, Phase 7). */
function MemberVaultAccess({
    name,
    access,
}: {
    name: string;
    access: TeamVaultAccess;
}) {
    if (access === null || (access.items.length === 0 && access.hidden === 0)) {
        return (
            <div className="cf-inset cf-row gap-3">
                <KeyRound
                    className="ic"
                    size={20}
                    strokeWidth={1.5}
                    aria-hidden="true"
                />
                <span className="t-body t-muted">
                    {name} has no vault access.
                </span>
            </div>
        );
    }

    if (access.items.length === 0) {
        return (
            <p className="t-body t-muted m-0">
                {name} can open {access.hidden}{' '}
                {access.hidden === 1 ? 'item' : 'items'} you can’t see.
            </p>
        );
    }

    return (
        <>
            <ul className="tm-vault">
                {access.items.map((item) => (
                    <li key={item.id} className="tm-vault-item">
                        <Avatar
                            name={item.name}
                            initials={item.monogram}
                            size="sm"
                            tile
                            label={false}
                        />
                        <span className="tm-vault-text">
                            <Link
                                href={
                                    vaultIndex({ query: { item: item.id } }).url
                                }
                                className="t-body tm-vault-link font-medium"
                            >
                                {item.name}
                            </Link>
                            <span className="t-caption t-muted">
                                {item.vault}
                            </span>
                        </span>
                        <LevelPill level={item.level} />
                    </li>
                ))}
            </ul>
            {access.hidden > 0 && (
                <p className="t-caption t-muted m-0">
                    and {access.hidden} more you can’t see
                </p>
            )}
        </>
    );
}
