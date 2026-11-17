import { router } from '@inertiajs/react';
import { ArrowUpRight, Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Skeleton } from '@/components/ui/skeleton';
import { formatInr } from '@/lib/format';
import { cn } from '@/lib/utils';
import { checkout as checkoutRoute } from '@/routes/billing';
import type {
    BillingCurrency,
    BillingCycle,
    PlanOption,
    UpgradeContext,
} from '@/types';

/** ₹2,499 or $35 — whole units, the way plans are priced. */
export function planPrice(
    amount: number | null,
    currency: BillingCurrency,
): string {
    if (amount === null) {
        return 'Custom price';
    }

    return currency === 'inr'
        ? formatInr(amount, { decimals: 'never' })
        : `$${(amount / 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}`;
}

const unit = (cycle: BillingCycle) => (cycle === 'yearly' ? 'year' : 'month');

/** What each plan adds, as in Billing.dc.html's plan cards. */
const ADDS: Record<string, string> = {
    starter: 'One company, timesheets, money and reports',
    growth: 'Settlements, capital accounts, vault sharing',
    business: 'Adds custom roles, audit log and vault policies',
    enterprise: 'Adds SSO, custom limits and invoicing',
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    context: UpgradeContext | null;
    /** Why it opened (a plan limit, read-only); shown above the plans. */
    message?: string | null;
    /** The viewer may change the plan (workspace owner / Admin). */
    canManage: boolean;
};

/**
 * "Change your plan" (Billing.dc.html, D82): plan cards (cheaper ones disabled once
 * subscribed — downgrades go through the portal), the billing period, currency before
 * the first payment, and a summary. Confirming sends a first subscription to Stripe
 * Checkout and swaps an existing one (prorated). Enterprise is "Talk to us".
 */
export function UpgradeModal({
    open,
    onOpenChange,
    context,
    message,
    canManage,
}: Props) {
    const [pick, setPick] = useState<string | null>(null);
    const [cycle, setCycle] = useState<BillingCycle>('monthly');
    const [currency, setCurrency] = useState<BillingCurrency>('inr');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || context === null) {
            return;
        }

        const ranked = context.plans;
        const current = ranked.find((p) => p.key === context.current.plan);
        const next =
            ranked.find(
                (p) =>
                    current !== undefined &&
                    p.position > current.position &&
                    !p.customPricing,
            ) ?? current;

        setPick(
            context.current.hasSubscription
                ? (next?.key ?? null)
                : (current?.key ?? next?.key ?? null),
        );
        setCycle(context.current.cycle);
        setCurrency(context.current.currency);
        setError(null);
    }, [open, context]);

    const current = context?.plans.find((p) => p.key === context.current.plan);
    const subscribed = context?.current.hasSubscription === true;
    const options: PlanOption[] = context?.plans ?? [];
    const chosen = options.find((p) => p.key === pick) ?? null;
    const lower = (p: PlanOption) =>
        subscribed && current !== undefined && p.position < current.position;
    // A cancelled plan still in its paid period can be chosen again: it resumes (D80).
    const resuming = context?.current.status === 'canceled';
    const same =
        subscribed &&
        !resuming &&
        chosen !== null &&
        chosen.key === context?.current.plan &&
        cycle === context.current.cycle;
    const upgrading =
        chosen !== null &&
        (current === undefined || chosen.position > current.position);
    const isEnterprise = chosen?.customPricing === true;
    const price = chosen?.prices[cycle][currency] ?? null;
    const sellable = chosen?.purchasable[cycle][currency] === true;
    const saving = context?.plans
        .map((p) => p.yearlySaving[currency])
        .find((s) => s !== null && s > 0);

    const confirmLabel = isEnterprise
        ? 'Talk to us first'
        : same
          ? 'This is your plan'
          : resuming && chosen?.key === context?.current.plan
            ? `Resume ${chosen.name}`
            : !subscribed
              ? `Continue with ${chosen?.name ?? 'a plan'}`
              : upgrading
                ? `Upgrade to ${chosen?.name ?? ''}`
                : `Switch to ${cycle}`;

    const confirm = () => {
        if (chosen === null || same || isEnterprise) {
            return;
        }

        setBusy(true);
        setError(null);
        router.post(
            checkoutRoute().url,
            { plan: chosen.key, cycle, currency },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    setError(errors.plan ?? 'That didn’t work. Try again.'),
                onFinish: () => setBusy(false),
            },
        );
    };

    const subtitle =
        context === null
            ? 'Loading plans…'
            : `${context.workspace.name} · ${context.workspace.companies} ${context.workspace.companies === 1 ? 'company' : 'companies'} · ${context.workspace.users} ${context.workspace.users === 1 ? 'user' : 'users'}`;

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={canManage ? 'Change your plan' : 'Your plan’s limit'}
            subtitle={subtitle}
            icon={ArrowUpRight}
            size="lg"
            footer={
                canManage ? (
                    <>
                        <Button
                            variant="secondary"
                            size="lg"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            size="lg"
                            disc={Check}
                            loading={busy}
                            disabled={
                                context === null ||
                                chosen === null ||
                                same ||
                                isEnterprise ||
                                !context.gatewayReady ||
                                !sellable
                            }
                            onClick={confirm}
                        >
                            {confirmLabel}
                        </Button>
                    </>
                ) : (
                    <Button
                        variant="primary"
                        size="lg"
                        onClick={() => onOpenChange(false)}
                    >
                        OK
                    </Button>
                )
            }
        >
            {message && (
                <AlertBanner
                    variant="warn"
                    slim
                    title={message}
                    role="status"
                />
            )}

            {!canManage && context !== null && (
                <p className="t-body m-0">
                    Only the workspace owner or an Admin can change the plan.
                    Ask {context.workspace.owner} to upgrade{' '}
                    {context.workspace.name}; everything you’ve added stays as
                    it is.
                </p>
            )}

            {canManage && context === null && (
                <div className="upg-grid" aria-hidden="true">
                    {[0, 1, 2].map((i) => (
                        <Skeleton key={i} height={112} />
                    ))}
                </div>
            )}

            {canManage && context !== null && (
                <>
                    <div
                        role="radiogroup"
                        aria-label="Plan"
                        className="upg-grid"
                    >
                        {options.map((p) => (
                            <label
                                key={p.key}
                                className={cn(
                                    'cf-radio upg-card',
                                    pick === p.key && 'on',
                                    lower(p) && 'off',
                                )}
                            >
                                <input
                                    className="cf-sr"
                                    type="radio"
                                    name="plan"
                                    checked={pick === p.key}
                                    disabled={lower(p)}
                                    onChange={() => setPick(p.key)}
                                />
                                <span
                                    className="cf-radio-dot"
                                    style={{ marginTop: 2 }}
                                />
                                <span className="cf-stack" style={{ gap: 6 }}>
                                    <span
                                        className="cf-row"
                                        style={{ gap: 6, flexWrap: 'wrap' }}
                                    >
                                        <span className="t-label-strong">
                                            {p.name}
                                        </span>
                                        {p.key === context.current.plan && (
                                            <span className="cf-pill cf-pill--sm cf-pill--outline">
                                                {context.current.status ===
                                                'trial'
                                                    ? 'Trial'
                                                    : 'Current'}
                                            </span>
                                        )}
                                    </span>
                                    <span className="t-money-sm">
                                        {p.customPricing
                                            ? 'Custom price'
                                            : `${planPrice(p.prices[cycle][currency], currency)} / ${unit(cycle)}`}
                                    </span>
                                    <span className="t-caption t-muted">
                                        {ADDS[p.key] ?? p.tagline}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>

                    <div className="cf-row flex-wrap" style={{ gap: 16 }}>
                        <div className="cf-field">
                            <span className="cf-label" id="up-c">
                                Billing period
                            </span>
                            <SegmentedControl
                                aria-labelledby="up-c"
                                value={cycle}
                                onValueChange={setCycle}
                                options={[
                                    { value: 'monthly', label: 'Monthly' },
                                    {
                                        value: 'yearly',
                                        label:
                                            saving !== undefined
                                                ? `Yearly · save ${saving}%`
                                                : 'Yearly',
                                    },
                                ]}
                            />
                        </div>
                        {!subscribed && (
                            <div className="cf-field">
                                <span className="cf-label" id="up-cur">
                                    Currency
                                </span>
                                <SegmentedControl
                                    aria-labelledby="up-cur"
                                    value={currency}
                                    onValueChange={setCurrency}
                                    options={[
                                        { value: 'inr', label: '₹ INR' },
                                        { value: 'usd', label: '$ USD' },
                                    ]}
                                />
                            </div>
                        )}
                    </div>

                    {isEnterprise && (
                        <AlertBanner
                            variant="info"
                            slim
                            title="Enterprise is set up with our team"
                            action={
                                <a
                                    href="mailto:sales@coffer.app?subject=Coffer%20Enterprise"
                                    className="cf-btn cf-btn--secondary"
                                >
                                    Talk to us
                                </a>
                            }
                        >
                            We’ll agree limits, SSO and invoicing with you, then
                            switch your plan.
                        </AlertBanner>
                    )}

                    {!context.gatewayReady && (
                        <AlertBanner
                            variant="info"
                            slim
                            title="Payments aren’t set up yet"
                        >
                            Add the Stripe keys to this server to take payments
                            (docs/BILLING_TESTING.md).
                        </AlertBanner>
                    )}

                    {chosen !== null && !isEnterprise && (
                        <div
                            className="cf-inset flex flex-col gap-2"
                            style={{ background: 'var(--surface-muted)' }}
                        >
                            <div className="cf-kv t-body">
                                <span>
                                    {chosen.name} · {cycle}
                                </span>
                                <span className="t-money">
                                    {planPrice(price, currency)} / {unit(cycle)}
                                </span>
                            </div>
                            <div className="cf-kv t-body">
                                <span className="t-muted">
                                    {subscribed
                                        ? 'Due today'
                                        : context.current.status === 'trial'
                                          ? 'Due today · your trial days carry over'
                                          : 'Due today'}
                                </span>
                                <span className="t-money">
                                    {subscribed
                                        ? 'Prorated for the rest of this period'
                                        : context.current.status === 'trial'
                                          ? planPrice(0, currency)
                                          : planPrice(price, currency)}
                                </span>
                            </div>
                            <div className="cf-kv t-caption t-muted">
                                <span>
                                    {subscribed
                                        ? 'Charged to your card on file'
                                        : 'Card details go to Stripe, never to Coffer'}
                                </span>
                                <span>
                                    {currency === 'inr'
                                        ? 'GST added on the invoice'
                                        : 'Local taxes may apply'}
                                </span>
                            </div>
                            {!sellable && context.gatewayReady && (
                                <span className="cf-errtext">
                                    {chosen.name} isn’t set up for {cycle}{' '}
                                    payments in {currency.toUpperCase()} yet.
                                </span>
                            )}
                        </div>
                    )}

                    {error && (
                        <span className="cf-errtext" role="alert">
                            {error}
                        </span>
                    )}
                </>
            )}
        </Modal>
    );
}
