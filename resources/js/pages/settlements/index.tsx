import { Head, Link, router } from '@inertiajs/react';
import { DateWithVoucher } from '@/components/finance/voucher-no';
import {
    ArrowLeftRight,
    ArrowRight,
    Check,
    Ellipsis,
    Lock,
    Pencil,
    Scale,
    Trash2,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { BalanceStrip } from '@/components/finance/balance-strip';
import { cleanQuery, FyMenu } from '@/components/finance/fy-menu';
import { TransferDrawer } from '@/components/finance/transfer-drawer';
import type { TransferTarget } from '@/components/finance/transfer-drawer';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner, AlertMoney } from '@/components/ui/alert-banner';
import { Avatar } from '@/components/ui/avatar';
import { Button, buttonClass } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Money } from '@/components/ui/money';
import { StatusPill } from '@/components/ui/status-pill';
import { formatDate, formatInr } from '@/lib/format';
import { index as capitalIndex } from '@/routes/capital-accounts';
import { create as createCompany } from '@/routes/companies';
import {
    destroy as destroyTransfer,
    index as settlementsIndex,
} from '@/routes/settlements';
import type {
    ClosableFiscalYear,
    SettlementTransferRow,
    SideSettlementRow,
    SuggestedTransfer,
} from '@/types/finance';

type JvProps = {
    company: { name: string; jv: true };
    today: string;
    fy: ClosableFiscalYear;
    asOf: string;
    balance: {
        currentPaise: number;
        openingPaise: number;
        incomePaise: number;
        expensesPaise: number;
        capitalAndLoansPaise: number;
        capitalPaise: number;
        personalPaise: number;
    };
    netProfitPaise: number;
    sides: SideSettlementRow[];
    suggested: SuggestedTransfer[];
    transfers: SettlementTransferRow[];
    abilities: { record: boolean };
};

type Props = JvProps | { company: { name: string; jv: false }; today: string };

const pct = (bps: number) =>
    `${Number.isInteger(bps / 100) ? bps / 100 : (bps / 100).toFixed(1)}%`;

/**
 * Settlements (Settlements.dc.html, spec §13). A joint venture sees its balance card,
 * how the split works, the banner (one amount for two sides, the fewest transfers for
 * more), the per-side table and the recorded transfers, with Record / edit / delete.
 * A Single company gets the design's empty state.
 */
export default function Settlements(props: Props) {
    if (!props.company.jv) {
        return <SingleCompany name={props.company.name} />;
    }

    return <JointVenture {...(props as JvProps)} />;
}

function SingleCompany({ name }: { name: string }) {
    return (
        <>
            <Head title="Settlements" />
            <PageHeader
                kicker="Finance"
                title="Settlements"
                crumbs={[{ title: 'Settlements' }]}
            />
            <EmptyState
                icon={ArrowLeftRight}
                muted
                headingLevel={2}
                title="Settlements are for joint ventures"
                description={`${name} is a single company, so there are no sides to settle. Partner balances live in Capital accounts.`}
                className="st-empty"
                actions={
                    <>
                        <Link
                            href={capitalIndex().url}
                            className={buttonClass({ variant: 'secondary' })}
                        >
                            Open capital accounts
                        </Link>
                        <Link
                            href={createCompany().url}
                            className={buttonClass({ variant: 'ghost' })}
                        >
                            Set up a joint venture
                        </Link>
                    </>
                }
            />
        </>
    );
}

function JointVenture(props: JvProps) {
    const {
        company,
        fy,
        balance,
        netProfitPaise,
        sides,
        suggested,
        transfers,
        abilities,
        today,
    } = props;
    const [target, setTarget] = useState<TransferTarget | null>(null);
    const [removing, setRemoving] = useState<SettlementTransferRow | null>(
        null,
    );
    const [deleting, setDeleting] = useState(false);

    const two = sides.length === 2;
    const equalShares = sides.every((s) => s.shareBps === sides[0]?.shareBps);
    const totalOut = sides.reduce((s, x) => s + x.paidOutPaise, 0);
    const sum = (key: keyof SideSettlementRow) =>
        sides.reduce((s, x) => s + Number(x[key]), 0);
    const held = (s: SideSettlementRow) => s.netHeldPaise + s.pooledPaise;
    const shareLine = two
        ? `${sides.map((s) => Math.round(s.shareBps / 100)).join('/')} sides`
        : `${sides.length} sides`;
    const recordNew = (suggestion: SuggestedTransfer | null = null) =>
        setTarget({
            mode: 'new',
            suggestion: suggestion ?? suggested[0] ?? null,
        });

    const columns: DataTableColumn<SideSettlementRow>[] = [
        {
            key: 'side',
            header: 'Side',
            width: 'minmax(170px,1.3fr)',
            className: 'cf-row t-label-strong',
            cell: (s) => (
                <>
                    <Avatar name={s.name} size="sm" graphite label={false} />
                    {s.name}
                </>
            ),
        },
        {
            key: 'share',
            header: 'Share',
            width: '70px',
            numeric: true,
            cell: (s) => pct(s.shareBps),
        },
        {
            key: 'collected',
            header: 'Cash collected',
            width: 'minmax(104px,1fr)',
            numeric: true,
            cell: (s) => formatInr(s.collectedPaise),
        },
        {
            key: 'paid',
            header: 'Cash paid',
            width: 'minmax(104px,1fr)',
            numeric: true,
            cell: (s) => formatInr(s.paidPaise),
        },
        {
            key: 'held',
            header: 'Net held',
            width: 'minmax(104px,1fr)',
            numeric: true,
            cell: (s) => (
                <>
                    <span className="block">{formatInr(held(s))}</span>
                    {s.pooledPaise !== 0 && (
                        <span className="t-caption st-note">
                            incl. {formatInr(s.pooledPaise)} JV account
                        </span>
                    )}
                </>
            ),
        },
        {
            key: 'entitled',
            header: 'Entitled',
            width: 'minmax(104px,1fr)',
            numeric: true,
            cell: (s) => formatInr(s.entitledPaise),
        },
        {
            key: 'out',
            header: 'Paid out',
            width: 'minmax(104px,1fr)',
            numeric: true,
            cell: (s) => formatInr(s.paidOutPaise),
        },
        {
            key: 'in',
            header: 'Paid in',
            width: 'minmax(104px,1fr)',
            numeric: true,
            cell: (s) => formatInr(s.paidInPaise),
        },
        {
            key: 'remaining',
            header: 'Remaining',
            width: 'minmax(104px,1fr)',
            numeric: true,
            className: (s) =>
                `font-semibold ${s.status === 'owes' ? 'st-owes' : s.status === 'owed' ? 'st-owed' : ''}`,
            cell: (s) => (
                <>
                    <span className="block">
                        {formatInr(
                            Math.abs(s.remainingPaise) <= 100
                                ? 0
                                : Math.abs(s.remainingPaise),
                        )}
                    </span>
                    <span className="t-caption st-note">
                        {s.status === 'owes'
                            ? 'to pay'
                            : s.status === 'owed'
                              ? 'to receive'
                              : 'settled'}
                    </span>
                </>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '110px',
            skeleton: 'pill',
            cell: (s) => <StatusPill status={s.status} />,
        },
    ];

    return (
        <>
            <Head title="Settlements" />
            <PageHeader
                kicker="Finance"
                title="Settlements"
                crumbs={[{ title: 'Settlements' }]}
                actions={
                    <>
                        <FyMenu
                            fy={fy}
                            onPick={(start) =>
                                router.get(
                                    settlementsIndex().url,
                                    cleanQuery({ fy: start }),
                                    { preserveScroll: true, replace: true },
                                )
                            }
                        />
                        {abilities.record && (
                            <Button
                                variant="primary"
                                size="lg"
                                disc={ArrowRight}
                                onClick={() => recordNew()}
                            >
                                Record transfer
                            </Button>
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
                    Its transfers can’t change. An Admin can reopen it in
                    Settings.
                </AlertBanner>
            )}

            <section className="st-top">
                <article className="cf-card cf-card--lime st-hero">
                    <div className="ln-hero-glow" aria-hidden="true" />
                    <span className="t-label-strong ln-hero-tag">
                        JV company balance
                    </span>
                    <div className="ln-hero-figure">
                        <span className="t-money-unit ln-hero-unit">
                            {balance.currentPaise < 0 ? '−₹' : '₹'}
                        </span>
                        <span className="t-figure-hero">
                            {formatInr(Math.abs(balance.currentPaise), {
                                symbol: false,
                            })}
                        </span>
                    </div>
                    <BalanceStrip
                        terms={{
                            ...balance,
                            otherPaise:
                                balance.capitalAndLoansPaise -
                                balance.capitalPaise -
                                balance.personalPaise,
                        }}
                    />
                </article>
                <article className="cf-card st-how">
                    <h2 className="cf-dot-title t-title">
                        How the split works
                    </h2>
                    <div className="st-insets">
                        {equalShares && (
                            <div className="cf-inset cf-stack">
                                <span className="t-caption t-muted">
                                    Each side is entitled to
                                </span>
                                <span className="t-money st-fig">
                                    {formatInr(sides[0]?.entitledPaise ?? 0)}
                                </span>
                                <span className="t-caption t-muted">
                                    {pct(sides[0]?.shareBps ?? 0)} of{' '}
                                    {formatInr(netProfitPaise)}
                                </span>
                            </div>
                        )}
                        {sides.map((s) => {
                            const now =
                                held(s) - s.paidOutPaise + s.paidInPaise;

                            return (
                                <div key={s.id} className="cf-inset cf-stack">
                                    <span className="t-caption t-muted">
                                        {s.name} holds
                                    </span>
                                    <span className="t-money st-fig">
                                        {formatInr(now)}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {s.paidOutPaise > 0
                                            ? `after ${formatInr(s.paidOutPaise)} paid out`
                                            : s.paidInPaise > 0
                                              ? `after ${formatInr(s.paidInPaise)} paid in`
                                              : equalShares
                                                ? 'no transfers yet'
                                                : `entitled to ${formatInr(s.entitledPaise)} (${pct(s.shareBps)})`}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                    <p className="t-body t-muted m-0">
                        A side that holds more cash than it’s entitled to owes
                        the difference to the other side.{' '}
                        {abilities.record
                            ? 'Record the bank transfer here once it’s made.'
                            : 'An Admin records the bank transfer here once it’s made.'}
                    </p>
                </article>
            </section>

            {suggested.length === 0 ? (
                <AlertBanner
                    variant="pos"
                    icon={Check}
                    iconStrokeWidth={2}
                    title="All sides settled"
                    role="status"
                >
                    Each side now holds exactly what it’s entitled to.
                </AlertBanner>
            ) : two || suggested.length === 1 ? (
                <AlertBanner
                    variant="graphite"
                    icon={Scale}
                    aria-label="Settlement alert"
                    title={
                        <>
                            {suggested[0]?.fromName} owes {suggested[0]?.toName}{' '}
                            <AlertMoney
                                paise={suggested[0]?.amountPaise ?? 0}
                            />
                        </>
                    }
                    badge={<StatusPill status="owes" />}
                    action={
                        abilities.record ? (
                            <Button
                                variant="onDark"
                                size="lg"
                                disc={ArrowRight}
                                onClick={() => recordNew()}
                            >
                                Record transfer
                            </Button>
                        ) : undefined
                    }
                >
                    {company.name} · {shareLine} · as of{' '}
                    {formatDate(props.asOf)}
                </AlertBanner>
            ) : (
                <AlertBanner
                    variant="graphite"
                    icon={Scale}
                    aria-label="Settlement alert"
                    title={`${suggested.length} transfers settle all ${sides.length} sides`}
                    badge={<StatusPill status="owes" />}
                >
                    <span className="st-moves">
                        {suggested.map((t) => (
                            <Fragment key={`${t.fromId}-${t.toId}`}>
                                <span className="st-move">
                                    {t.fromName} → {t.toName}{' '}
                                    <span className="t-money">
                                        {formatInr(t.amountPaise)}
                                    </span>
                                    {abilities.record && (
                                        <button
                                            type="button"
                                            className="cf-link"
                                            style={{ color: 'inherit' }}
                                            onClick={() => recordNew(t)}
                                        >
                                            Record
                                        </button>
                                    )}
                                </span>
                            </Fragment>
                        ))}
                    </span>
                </AlertBanner>
            )}

            <section
                className="cf-card flex min-w-0 flex-col gap-3"
                aria-labelledby="st-h"
            >
                <h2 id="st-h" className="cf-dot-title t-title">
                    By side
                </h2>
                <DataTable
                    aria-label="Settlement by side"
                    rows={sides}
                    columns={columns}
                    getRowId={(s) => String(s.id)}
                    minWidth={1180}
                    totals={{
                        side: 'Total',
                        share: pct(sum('shareBps')),
                        collected: formatInr(sum('collectedPaise')),
                        paid: formatInr(sum('paidPaise')),
                        held: formatInr(
                            sum('netHeldPaise') + sum('pooledPaise'),
                        ),
                        entitled: formatInr(sum('entitledPaise')),
                        out: formatInr(totalOut),
                        in: formatInr(sum('paidInPaise')),
                        remaining: formatInr(0),
                    }}
                />
                <p className="t-caption t-muted m-0">
                    Remaining = net held − paid out + paid in − entitled. A side
                    that holds more than it’s entitled to pays the difference.
                    Capital, loans and opening balances are left out.
                </p>
            </section>

            <section
                className="cf-card flex flex-col gap-2.5"
                aria-labelledby="tr-h"
            >
                <h2 id="tr-h" className="cf-dot-title t-title">
                    Recorded transfers
                </h2>
                {transfers.length === 0 ? (
                    <p className="t-body t-muted m-0">
                        No transfers in {fy.label} yet.
                    </p>
                ) : (
                    transfers.map((t) => (
                        <div
                            key={t.id}
                            className={`cf-inset st-trow${abilities.record ? ' st-trow--acts' : ''}`}
                        >
                            <DateWithVoucher
                                date={t.date}
                                voucher={t.voucher}
                            />
                            <span className="cf-row t-label-strong flex-wrap">
                                {t.fromName}
                                <Icon icon={ArrowRight} size={16} />
                                <span className="sr-only">to</span>
                                {t.toName}
                            </span>
                            <span className="t-money text-right">
                                {formatInr(t.amountPaise)}
                            </span>
                            <span className="t-money-sm t-muted">
                                {t.reference ?? 'No reference'}
                            </span>
                            <span className="t-caption t-muted">
                                {t.by ? `by ${t.by}` : ''}
                            </span>
                            {abilities.record && (
                                <DropdownMenu
                                    aria-label={`Actions for the ${formatInr(t.amountPaise)} transfer`}
                                    align="end"
                                    trigger={
                                        <IconButton
                                            icon={Ellipsis}
                                            size="sm"
                                            aria-label={`More actions for the ${formatInr(t.amountPaise)} transfer on ${formatDate(t.date)}`}
                                        />
                                    }
                                    entries={[
                                        {
                                            label: 'Edit',
                                            icon: Pencil,
                                            onSelect: () =>
                                                setTarget({
                                                    mode: 'edit',
                                                    transfer: t,
                                                }),
                                        },
                                        {
                                            label: 'Delete',
                                            icon: Trash2,
                                            danger: true,
                                            onSelect: () => setRemoving(t),
                                        },
                                    ]}
                                />
                            )}
                        </div>
                    ))
                )}
            </section>

            {abilities.record && (
                <TransferDrawer
                    target={target}
                    onClose={() => setTarget(null)}
                    company={company.name}
                    sides={sides}
                    suggested={suggested}
                    today={today}
                />
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title="Delete this transfer?"
                description={
                    removing ? (
                        <>
                            {removing.fromName} → {removing.toName} ·{' '}
                            <Money
                                paise={removing.amountPaise}
                                size="sm"
                                tone="none"
                            />{' '}
                            on {formatDate(removing.date)}. The entry is
                            reversed, so what each side owes updates straight
                            away.
                        </>
                    ) : undefined
                }
                icon={Trash2}
                confirmLabel="Delete transfer"
                loading={deleting}
                onConfirm={() => {
                    if (!removing) {
                        return;
                    }

                    router.delete(destroyTransfer(removing.id).url, {
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
