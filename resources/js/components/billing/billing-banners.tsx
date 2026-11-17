import { Link, usePage } from '@inertiajs/react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { openUpgrade } from '@/lib/upgrade';
import { index as billingRoute } from '@/routes/billing';

/**
 * The shell's billing line (D80, D81): the last week of the trial, a failed payment, an
 * ended trial in its grace days, and read-only. Hidden on the Billing page itself.
 */
export function BillingBanner() {
    const { billingStatus: b, currentCompany } = usePage().props;
    const onBilling =
        typeof window !== 'undefined' &&
        window.location.pathname.startsWith('/billing');

    if (b === null || currentCompany === null || onBilling) {
        return null;
    }

    const action = b.canManage ? (
        <Link href={billingRoute().url} className="cf-btn cf-btn--secondary">
            {b.status === 'past_due' ? 'Update payment' : 'Choose a plan'}
        </Link>
    ) : undefined;
    const ask = b.canManage ? '' : ` Ask ${b.owner} to choose a plan.`;

    if (b.readOnly) {
        return (
            <AlertBanner
                variant="neg"
                slim
                role="alert"
                title="This workspace is read-only"
                action={action}
            >
                {b.status === 'past_due'
                    ? 'Payments are overdue.'
                    : 'The trial or plan has ended.'}{' '}
                Everything stays visible and nothing is deleted; changes are
                paused until a plan is paid.{ask}
            </AlertBanner>
        );
    }

    if (b.status === 'past_due') {
        return (
            <AlertBanner
                variant="warn"
                slim
                title="A payment didn’t go through"
                action={action}
            >
                Update the card by {b.graceEndsAt} to keep making changes.{ask}
            </AlertBanner>
        );
    }

    if (b.status === 'expired') {
        return (
            <AlertBanner
                variant="warn"
                slim
                title="Your trial has ended"
                action={action}
            >
                Choose a plan by {b.graceEndsAt} to keep making changes. Your
                data stays as it is.{ask}
            </AlertBanner>
        );
    }

    if (
        b.status === 'trial' &&
        b.trialDaysLeft !== null &&
        b.trialDaysLeft <= 7
    ) {
        return (
            <AlertBanner
                variant="info"
                slim
                role="status"
                title={
                    b.trialDaysLeft <= 1
                        ? 'Your trial ends tomorrow'
                        : `Your trial ends in ${b.trialDaysLeft} days`
                }
                action={action}
            >
                You’re trying {b.plan ?? 'Coffer'}. Pick a plan to keep going;
                the days you have left carry over.{ask}
            </AlertBanner>
        );
    }

    return null;
}

/**
 * "Near the limit" on the page that adds the thing (D82): users on Members, vault items
 * in the vault, companies in the wizard. At the limit it says creating will need an upgrade.
 */
export function PlanLimitBanner() {
    const { planLimit: l, billingStatus } = usePage().props;

    if (l === null) {
        return null;
    }

    return (
        <AlertBanner
            variant="warn"
            slim
            role="status"
            title={
                l.full
                    ? `You’ve used all ${l.limit} ${l.noun} on ${billingStatus?.plan ?? 'your plan'}`
                    : `${l.used} of ${l.limit} ${l.noun} used`
            }
            action={
                <Button variant="secondary" onClick={() => openUpgrade()}>
                    {billingStatus?.canManage ? 'Upgrade' : 'See plans'}
                </Button>
            }
        >
            {l.full
                ? 'Adding another needs a bigger plan. Nothing you already have is affected.'
                : 'You’re near your plan’s limit. Upgrading raises it straight away.'}
        </AlertBanner>
    );
}
