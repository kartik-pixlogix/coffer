import { Head, router, useForm } from '@inertiajs/react';
import { ArrowUpRight, CreditCard, Download, FileText } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { planPrice, UpgradeModal } from '@/components/billing/upgrade-modal';
import { PageHeader } from '@/components/shell/page-header';
import { useShell } from '@/components/shell/shell-context';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DataTable } from '@/components/ui/data-table';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Modal } from '@/components/ui/modal';
import { ProgressBar } from '@/components/ui/progress-bar';
import { StatusPill } from '@/components/ui/status-pill';
import { TextInput } from '@/components/ui/text-input';
import { useToast } from '@/components/ui/toast';
import { formatIndianNumber } from '@/lib/format';
import {
    details as detailsRoute,
    invoice as invoiceRoute,
    portal as portalRoute,
    sync as syncRoute,
} from '@/routes/billing';
import type {
    BillingCurrency,
    BillingCycle,
    BillingStatusKey,
    InvoiceRow,
    PlanOption,
    UpgradeContext,
    UsageRow,
} from '@/types';

type State = {
    status: BillingStatusKey;
    plan: { key: string; name: string } | null;
    cycle: BillingCycle;
    currency: BillingCurrency;
    trialEndsAt: string | null;
    trialDaysLeft: number | null;
    renewsAt: string | null;
    endsAt: string | null;
    graceEndsAt: string | null;
    readOnly: boolean;
    hasSubscription: boolean;
    paymentMethod: { brand: string; last4: string } | null;
    workspace: string;
    ownerName: string;
};

type Props = {
    state: State;
    details: { email: string; name: string | null; gstin: string | null };
    usage: UsageRow[];
    invoices: InvoiceRow[];
    invoicesFailed: boolean;
    plans: PlanOption[];
    gatewayReady: boolean;
    /** Local only: the setup note about Stripe keys (F6). */
    gatewayHint: boolean;
    /** Back from Checkout: synced from Stripe already, still confirming, or cancelled (B2, B8). */
    checkout: 'synced' | 'done' | 'cancelled' | null;
};

const STATUS_PILL: Record<
    BillingStatusKey,
    { label: string; tone: 'pos' | 'warn' | 'neg' | 'info' | 'neutral' }
> = {
    unmanaged: { label: 'No plan', tone: 'neutral' },
    trial: { label: 'Free trial', tone: 'info' },
    active: { label: 'Active', tone: 'pos' },
    past_due: { label: 'Payment failed', tone: 'neg' },
    canceled: { label: 'Cancelled', tone: 'warn' },
    expired: { label: 'Ended', tone: 'neg' },
};

function storage(mb: number): string {
    return mb >= 1024
        ? `${formatIndianNumber(Math.round((mb / 1024) * 10) / 10, 1)} GB`
        : `${formatIndianNumber(mb)} MB`;
}

function Meter({ row }: { row: UsageRow }) {
    const isStorage = row.key === 'storage';
    const pct =
        row.limit === null || row.limit === 0
            ? 0
            : Math.min(100, Math.round((row.used / row.limit) * 100));
    const near = row.limit !== null && pct >= 80;
    const show = (n: number) =>
        isStorage ? storage(n) : formatIndianNumber(n);

    return (
        <div className="flex flex-col gap-2">
            <div className="cf-kv items-center">
                <span className="cf-row" style={{ gap: 8 }}>
                    <span className="t-label-strong">{row.label}</span>
                    {near && (
                        <StatusPill
                            tone={pct >= 100 ? 'neg' : 'warn'}
                            size="sm"
                        >
                            {pct >= 100 ? 'At the limit' : 'Near the limit'}
                        </StatusPill>
                    )}
                </span>
                <span className="t-money-sm">
                    {show(row.used)} of{' '}
                    {row.limit === null ? 'unlimited' : show(row.limit)}
                </span>
            </div>
            <ProgressBar
                value={row.limit === null ? 0 : pct}
                variant={pct >= 100 ? 'neg' : near ? 'warn' : 'lime'}
                aria-label={`${row.label} used`}
                aria-valuetext={`${show(row.used)} of ${row.limit === null ? 'unlimited' : show(row.limit)}`}
            />
            <span className="t-caption t-muted">{row.detail}</span>
        </div>
    );
}

function DetailsModal({
    open,
    onOpenChange,
    details,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    details: Props['details'];
}) {
    const form = useForm({
        email: details.email,
        name: details.name ?? '',
        gstin: details.gstin ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(detailsRoute().url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title="Billing details"
            subtitle="On every new invoice"
            icon={FileText}
            dirty={form.isDirty}
            onSubmit={submit}
            footer={
                <>
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        variant="primary"
                        size="lg"
                        loading={form.processing}
                    >
                        Save details
                    </Button>
                </>
            }
        >
            <TextInput
                label="Billing email"
                type="email"
                value={form.data.email}
                onChange={(e) => form.setData('email', e.target.value)}
                error={form.errors.email}
                required
            />
            <TextInput
                label="Billed to"
                help="Company name and city, as it should read on the invoice"
                value={form.data.name}
                onChange={(e) => form.setData('name', e.target.value)}
                error={form.errors.name}
            />
            <TextInput
                label="GSTIN on invoices"
                help="Optional, e.g. 27ABCDE1234F1Z5"
                value={form.data.gstin}
                onChange={(e) =>
                    form.setData('gstin', e.target.value.toUpperCase())
                }
                error={form.errors.gstin}
                maxLength={15}
            />
        </Modal>
    );
}

/**
 * Billing (Billing.dc.html, spec §20, D80): the current plan on the lime card with the
 * trial countdown or renewal, live usage meters, invoices from Stripe, the payment method
 * and billing details, and the Upgrade modal. Cards are handled on Stripe's pages only.
 */
export default function Billing({
    state,
    details,
    usage,
    invoices,
    invoicesFailed,
    plans,
    gatewayReady,
    gatewayHint,
    checkout,
}: Props) {
    const toast = useToast();
    const { workspace } = useShell();
    const [upgradeOpen, setUpgradeOpen] = useState(false);
    const [detailsOpen, setDetailsOpen] = useState(false);

    useEffect(() => {
        if (checkout === 'synced') {
            toast.success('Thanks! Your plan is on and your card is saved.');
        } else if (checkout === 'done') {
            toast.success(
                'Thanks! Stripe is still confirming the payment. Use “Refresh from Stripe” in a moment.',
            );
        } else if (checkout === 'cancelled') {
            toast.info('Checkout cancelled. Nothing was charged.');
        }
    }, [checkout, toast]);

    const current = plans.find((p) => p.key === state.plan?.key) ?? null;
    const price = current?.prices[state.cycle][state.currency] ?? null;
    const unit = state.cycle === 'yearly' ? 'year' : 'month';
    const companies = usage.find((u) => u.key === 'companies')?.used ?? 0;
    const users = usage.find((u) => u.key === 'users')?.used ?? 0;
    const context: UpgradeContext = {
        plans,
        current: {
            plan: state.plan?.key ?? null,
            cycle: state.cycle,
            currency: state.currency,
            status: state.status,
            hasSubscription: state.hasSubscription,
        },
        workspace: {
            name: state.workspace,
            companies,
            users,
            owner: state.ownerName,
        },
        gatewayReady,
        gatewayHint,
    };
    const pill = STATUS_PILL[state.status];
    const [syncing, setSyncing] = useState(false);
    const refresh = () =>
        router.post(
            syncRoute().url,
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
                onError: (errors) =>
                    toast.error(errors.plan ?? 'Stripe couldn’t be reached.'),
            },
        );
    const portal = () => router.get(portalRoute().url);

    const columns: DataTableColumn<InvoiceRow>[] = [
        {
            key: 'number',
            header: 'Invoice',
            width: '150px',
            cell: (i) => <span className="t-money-sm">{i.number}</span>,
        },
        {
            key: 'date',
            header: 'Date',
            width: '130px',
            className: 't-body t-muted',
            cell: (i) => i.date,
        },
        {
            key: 'period',
            header: 'Period',
            width: 'minmax(160px,1fr)',
            className: 't-body',
            cell: (i) => i.period,
        },
        {
            key: 'plan',
            header: 'Plan',
            width: 'minmax(170px,1.2fr)',
            className: 't-body',
            cell: (i) => i.plan,
        },
        {
            key: 'amount',
            header: 'Amount',
            width: '130px',
            numeric: true,
            cell: (i) => i.amount,
        },
        {
            key: 'status',
            header: 'Status',
            width: '110px',
            skeleton: 'pill',
            cell: (i) =>
                i.status === 'paid' ? (
                    <StatusPill status="approved" size="sm">
                        Paid
                    </StatusPill>
                ) : i.status === 'open' ? (
                    <StatusPill status="pending" size="sm">
                        Due
                    </StatusPill>
                ) : (
                    <StatusPill tone="neutral" size="sm">
                        {i.status.charAt(0).toUpperCase() + i.status.slice(1)}
                    </StatusPill>
                ),
        },
    ];

    return (
        <>
            <Head title="Billing" />
            <PageHeader
                kicker="Account"
                title="Billing"
                crumbs={[{ title: 'Billing' }]}
                actions={
                    <>
                        <a
                            href="/pricing"
                            className="cf-btn cf-btn--secondary cf-btn--lg"
                        >
                            Compare plans
                        </a>
                        {state.plan?.key !== 'enterprise' && (
                            <Button
                                variant="primary"
                                size="lg"
                                disc={ArrowUpRight}
                                onClick={() => setUpgradeOpen(true)}
                            >
                                {state.hasSubscription
                                    ? 'Upgrade'
                                    : 'Choose a plan'}
                            </Button>
                        )}
                    </>
                }
            />

            {state.readOnly && (
                <AlertBanner
                    variant="neg"
                    title={`${state.workspace} is read-only`}
                    role="alert"
                >
                    Everything stays visible and nothing is deleted. Choose a
                    plan
                    {state.status === 'past_due'
                        ? ' or update the card'
                        : ''}{' '}
                    to make changes again.
                </AlertBanner>
            )}
            {!state.readOnly && state.status === 'past_due' && (
                <AlertBanner
                    variant="warn"
                    title="A payment didn’t go through"
                    action={
                        <Button variant="secondary" onClick={portal}>
                            Update payment
                        </Button>
                    }
                >
                    Coffer stays fully usable until {state.graceEndsAt}, then
                    turns read-only until it’s paid.
                </AlertBanner>
            )}
            {!gatewayReady && (
                <AlertBanner
                    variant="info"
                    slim
                    title="Online payments aren’t available yet"
                >
                    {gatewayHint
                        ? 'Add STRIPE_KEY, STRIPE_SECRET and the price ids to take payments (see docs/BILLING_TESTING.md).'
                        : 'Contact support and we’ll set up your plan.'}
                </AlertBanner>
            )}

            <section className="bill-top">
                <article
                    className="cf-card cf-card--lime bill-plan"
                    aria-labelledby="cp-h"
                >
                    <div aria-hidden="true" className="bill-glow" />
                    <span className="t-label-strong bill-chip">
                        {state.status === 'trial'
                            ? 'Free trial'
                            : 'Current plan'}
                    </span>
                    <div className="relative">
                        <h2
                            id="cp-h"
                            className="t-display m-0"
                            style={{ color: 'var(--on-lime)' }}
                        >
                            {state.plan?.name ?? 'No plan'}
                        </h2>
                        {price !== null && (
                            <div
                                className="cf-row mt-1.5"
                                style={{ gap: 6, alignItems: 'baseline' }}
                            >
                                <span
                                    className="t-money"
                                    style={{ fontSize: 26, lineHeight: '30px' }}
                                >
                                    {planPrice(price, state.currency)}
                                </span>
                                <span className="t-body">
                                    / {unit}
                                    {state.currency === 'inr' ? ' + GST' : ''}
                                </span>
                            </div>
                        )}
                    </div>
                    <div className="t-body bill-strip">
                        {state.status === 'trial' && (
                            <span>
                                Trial ends on{' '}
                                <strong>{state.trialEndsAt}</strong> ·{' '}
                                {state.trialDaysLeft === 1
                                    ? '1 day left'
                                    : `${state.trialDaysLeft ?? 0} days left`}
                            </span>
                        )}
                        {state.status === 'active' && state.renewsAt && (
                            <span>
                                Renews on <strong>{state.renewsAt}</strong>
                            </span>
                        )}
                        {state.status === 'canceled' && (
                            <span>
                                Ends on <strong>{state.endsAt}</strong>
                            </span>
                        )}
                        {state.status === 'expired' && (
                            <span>
                                Ended · read-only from{' '}
                                <strong>{state.graceEndsAt}</strong>
                            </span>
                        )}
                        <span>
                            {state.paymentMethod
                                ? `Paid with ${state.paymentMethod.brand} ··${state.paymentMethod.last4}`
                                : 'No card yet'}
                        </span>
                        <span>
                            {state.cycle === 'yearly' ? 'Yearly' : 'Monthly'}{' '}
                            billing
                        </span>
                        <StatusPill tone={pill.tone} size="sm">
                            {pill.label}
                        </StatusPill>
                    </div>
                    <div
                        className="cf-row relative flex-wrap"
                        style={{ gap: 8 }}
                    >
                        <Button
                            variant="glass"
                            onClick={() => setUpgradeOpen(true)}
                        >
                            {state.hasSubscription
                                ? 'Change billing period'
                                : 'Choose a plan'}
                        </Button>
                        {state.hasSubscription && (
                            <Button
                                variant="ghost"
                                style={{ color: 'var(--on-lime)' }}
                                onClick={portal}
                            >
                                {state.status === 'canceled'
                                    ? 'Resume plan'
                                    : 'Cancel plan'}
                            </Button>
                        )}
                    </div>
                </article>

                <article className="cf-card bill-usage" aria-labelledby="us-h">
                    <div className="cf-kv">
                        <h2 id="us-h" className="cf-dot-title t-title">
                            Usage
                        </h2>
                        <span className="t-caption t-muted">
                            Across {workspace.name} · updated just now
                        </span>
                    </div>
                    {usage.map((row) => (
                        <Meter key={row.key} row={row} />
                    ))}
                </article>
            </section>

            <section className="bill-bottom">
                <article
                    className="cf-card bill-invoices"
                    aria-labelledby="iv-h"
                >
                    <div className="cf-kv">
                        <h2 id="iv-h" className="cf-dot-title t-title">
                            Invoices
                        </h2>
                        <span className="t-caption t-muted">
                            Each invoice downloads as a PDF with GST shown
                        </span>
                    </div>
                    {invoicesFailed && (
                        <AlertBanner
                            variant="warn"
                            slim
                            title="Stripe couldn’t be reached"
                        >
                            Invoices will show again when it’s back.
                        </AlertBanner>
                    )}
                    <DataTable
                        aria-label="Invoices"
                        rows={invoices}
                        columns={columns}
                        getRowId={(i) => i.id}
                        minWidth={880}
                        rowActions={(i) => (
                            <IconButton
                                icon={Download}
                                size="sm"
                                iconSize={14}
                                aria-label={`Download ${i.number} as PDF`}
                                onClick={() =>
                                    window.location.assign(
                                        invoiceRoute(i.id).url,
                                    )
                                }
                            />
                        )}
                        actionsWidth="56px"
                        empty={{
                            title:
                                state.status === 'trial'
                                    ? 'No invoices yet. The first arrives when your trial ends.'
                                    : 'No invoices yet',
                            icon: FileText,
                        }}
                    />
                </article>

                <aside className="bill-aside">
                    <section
                        className="cf-card flex flex-col gap-3"
                        aria-labelledby="pm-h"
                    >
                        <h2 id="pm-h" className="cf-dot-title t-title">
                            Payment method
                        </h2>
                        <div className="cf-row" style={{ gap: 12 }}>
                            <span className="cf-avatar cf-avatar--lg cf-avatar--tile cf-avatar--graphite">
                                <Icon icon={CreditCard} size={20} />
                            </span>
                            <span className="cf-stack" style={{ flex: 1 }}>
                                <span className="t-label-strong">
                                    {state.paymentMethod
                                        ? `${state.paymentMethod.brand} ending ${state.paymentMethod.last4}`
                                        : 'No card on file'}
                                </span>
                                <span className="t-caption t-muted">
                                    {state.paymentMethod
                                        ? 'Kept by Stripe, never by Coffer'
                                        : 'Added when you choose a plan'}
                                </span>
                            </span>
                            {state.hasSubscription && (
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={portal}
                                    disabled={!gatewayReady}
                                >
                                    Update
                                </Button>
                            )}
                        </div>
                        {gatewayReady && (
                            <Button
                                variant="ghost"
                                size="sm"
                                loading={syncing}
                                onClick={refresh}
                            >
                                Refresh from Stripe
                            </Button>
                        )}
                        <div className="cf-divider" role="separator" />
                        <div className="cf-kv t-body">
                            <span className="t-muted">Billing email</span>
                            <span>{details.email}</span>
                        </div>
                        <div className="cf-kv t-body">
                            <span className="t-muted">GSTIN on invoices</span>
                            <span className="t-money-sm">
                                {details.gstin ?? '—'}
                            </span>
                        </div>
                        <div className="cf-kv t-body">
                            <span className="t-muted">Billed to</span>
                            <span>{details.name ?? state.workspace}</span>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="self-start"
                            onClick={() => setDetailsOpen(true)}
                        >
                            Edit billing details
                        </Button>
                    </section>
                </aside>
            </section>

            <UpgradeModal
                open={upgradeOpen}
                onOpenChange={setUpgradeOpen}
                context={context}
                canManage
            />
            <DetailsModal
                open={detailsOpen}
                onOpenChange={setDetailsOpen}
                details={details}
            />
        </>
    );
}
