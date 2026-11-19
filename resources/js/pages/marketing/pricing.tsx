import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { CheckList, FaqList } from '@/components/marketing/marketing-parts';
import { Reveal, RevealGroup, RevealItem } from '@/components/marketing/motion';
import type { Faq } from '@/components/marketing/marketing-parts';
import { SeoHead } from '@/components/marketing/seo-head';
import { buttonClass, ButtonLink } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import {
    bestYearlySaving,
    limitLabel,
    PLAN_CARD,
    planPrice,
} from '@/lib/plans';
import type { LimitKey } from '@/lib/plans';
import { cn } from '@/lib/utils';
import type {
    BillingCurrency,
    BillingCycle,
    PlanOption,
    SeoProps,
} from '@/types';

type Props = {
    plans: PlanOption[];
    defaultCurrency: BillingCurrency;
    trialDays: number;
    seo: SeoProps;
};

/**
 * The comparison table (Pricing.dc.html). A row is a limit (from the plan's columns),
 * a feature flag (from `plans.features`, PlanSeeder::FEATURES), or `true` = every plan.
 */
type Row =
    | { group: string }
    | { label: string; limit: LimitKey }
    | { label: string; feature: string }
    | { label: string; all: true };

const ROWS: Row[] = [
    { group: 'Companies and people' },
    { label: 'Companies', limit: 'companies' },
    { label: 'Users', limit: 'users' },
    { label: 'Joint ventures with sides', feature: 'joint_ventures' },
    { label: 'Admin, Partner, Member and Viewer roles', all: true },
    { label: 'Custom permission matrix', feature: 'custom_roles' },
    { group: 'Money' },
    { label: 'Income, expenses and approvals', all: true },
    { label: 'Capital accounts and partner loans', feature: 'capital_loans' },
    { label: 'Settlements between sides', feature: 'settlements' },
    { label: 'Reports with PDF and Excel export', feature: 'reports_export' },
    { label: 'Trial balance and balance sheet', feature: 'statements' },
    { group: 'Time' },
    { label: 'Timesheets, by day or by month', all: true },
    { label: 'Bulk import', feature: 'bulk_import' },
    { group: 'Vault' },
    { label: 'Vault items', limit: 'vaultItems' },
    { label: '2FA codes (TOTP)', feature: 'vault_totp' },
    { label: 'Sharing with people and teams', feature: 'vault_sharing' },
    { label: 'Health score', feature: 'vault_health' },
    { label: 'Audit log', feature: 'vault_audit' },
    { label: 'Vault policies and export controls', feature: 'vault_policies' },
    { group: 'Support and security' },
    { label: 'Email support', feature: 'email_support' },
    { label: 'Priority support', feature: 'priority_support' },
    { label: 'Single sign-on (SAML)', feature: 'sso' },
    { label: 'Onboarding help', feature: 'onboarding' },
];

const FAQS: Faq[] = [
    [
        'Can I try Coffer before I pay?',
        'Yes. Every plan starts with a free trial. Pick a plan when you’re ready to keep going.',
    ],
    [
        'What counts as a company?',
        'Each set of books counts as one company, whether it’s a single company or a joint venture. Sides inside a JV don’t count separately.',
    ],
    [
        'Can I change plans later?',
        'Yes. Upgrades start straight away and you pay the difference for the rest of the billing period. Downgrades start at your next renewal.',
    ],
    [
        'Are prices shown with GST?',
        'Rupee prices are shown before GST. Your invoice adds GST and shows your GSTIN.',
    ],
    [
        'Which currencies can I pay in?',
        'Rupees or US dollars. Choose at checkout; your invoices use the same currency.',
    ],
];

function Included({ yes }: { yes: boolean }) {
    return yes ? (
        <svg
            className="ic"
            width="18"
            height="18"
            viewBox="0 0 24 24"
            style={{ strokeWidth: 2.25 }}
            role="img"
            aria-label="Included"
        >
            <path d="M5 12.5l4.5 4.5L19 7.5" />
        </svg>
    ) : (
        <span className="t-faint" role="img" aria-label="Not included">
            —
        </span>
    );
}

/**
 * Pricing (design/screens/Pricing.dc.html, spec §20/§21): monthly / yearly and INR / USD
 * toggles, the four plan cards and the comparison table, all read from the plans table
 * (D80), then billing FAQs. Trial CTAs go to sign-up with `?plan=` (D83).
 */
export default function Pricing({
    plans,
    defaultCurrency,
    trialDays,
    seo,
}: Props) {
    const [cycle, setCycle] = useState<BillingCycle>('monthly');
    const [currency, setCurrency] = useState<BillingCurrency>(defaultCurrency);
    const yearly = cycle === 'yearly';
    const saving = bestYearlySaving(plans, currency);

    const price = (plan: PlanOption) =>
        plan.customPricing
            ? 'Custom'
            : planPrice(plan.prices[cycle][currency], currency);

    const note = (plan: PlanOption) => {
        if (plan.customPricing) {
            return 'Priced for your group';
        }

        const planSaving = plan.yearlySaving[currency];

        return yearly
            ? `Billed once a year${planSaving ? ` · save ${planSaving}%` : ''}`
            : 'Billed every month';
    };

    const href = (plan: PlanOption) =>
        plan.customPricing
            ? '/contact?topic=sales'
            : `/register?plan=${plan.key}`;

    return (
        <>
            <SeoHead
                seo={seo}
                title="Pricing"
                description={`Plans for one company or a whole group: Starter, Growth, Business and Enterprise. Monthly or yearly, in rupees or US dollars, with a ${trialDays}-day free trial.`}
            />

            <section
                className="lp-in lp-rise-group lp-rise-all"
                style={{ paddingTop: 48, textAlign: 'center' }}
                aria-labelledby="pr-h"
            >
                <span className="lp-eyebrow">
                    <span className="cf-dot-title" />
                    Pricing
                </span>
                <h1
                    id="pr-h"
                    className="lp-h1 lp-h1--sm"
                    style={{ margin: '20px auto 0', maxWidth: 880 }}
                >
                    Pay for the companies you run,{' '}
                    <span className="lp-faint">not the tools you juggle.</span>
                </h1>
                <p
                    className="t-body t-muted"
                    style={{
                        margin: '18px auto 0',
                        maxWidth: 620,
                        fontSize: 18,
                        lineHeight: '27px',
                    }}
                >
                    Every plan includes timesheets, income and expenses, reports
                    and a vault with 2FA codes. Try any plan before you pay.
                </p>
                <div className="lp-cta-row" style={{ marginTop: 28 }}>
                    <SegmentedControl<BillingCycle>
                        aria-label="Billing period"
                        value={cycle}
                        onValueChange={setCycle}
                        options={[
                            { value: 'monthly', label: 'Monthly' },
                            {
                                value: 'yearly',
                                label: (
                                    <>
                                        Yearly
                                        {saving !== null && (
                                            <span className="cf-pill cf-pill--sm cf-pill--graphite">
                                                Save {saving}%
                                            </span>
                                        )}
                                    </>
                                ),
                            },
                        ]}
                    />
                    <SegmentedControl<BillingCurrency>
                        aria-label="Currency"
                        value={currency}
                        onValueChange={setCurrency}
                        options={[
                            { value: 'inr', label: '₹ INR' },
                            { value: 'usd', label: '$ USD' },
                        ]}
                    />
                </div>
            </section>

            <section
                className="lp-in"
                style={{ paddingTop: 40 }}
                aria-label="Plans"
            >
                <RevealGroup
                    className="lp-grid-4"
                    style={{ alignItems: 'stretch' }}
                >
                    {plans.map((plan) => {
                        const card = PLAN_CARD[plan.key];

                        return (
                            <RevealItem
                                as="article"
                                lift
                                key={plan.key}
                                className={cn(
                                    'plan lp-card-lift',
                                    plan.popular && 'pop',
                                )}
                                aria-labelledby={`plan-${plan.key}`}
                            >
                                <div
                                    className="cf-kv"
                                    style={{ alignItems: 'center' }}
                                >
                                    <h2
                                        id={`plan-${plan.key}`}
                                        className="lp-h3"
                                    >
                                        {plan.name}
                                    </h2>
                                    {plan.popular && (
                                        <span className="cf-pill cf-pill--graphite">
                                            Popular
                                        </span>
                                    )}
                                </div>
                                <p className="t-body plan-body">
                                    {plan.tagline}
                                </p>
                                <div className="cf-stack" style={{ gap: 2 }}>
                                    <div
                                        className="cf-row"
                                        style={{
                                            gap: 6,
                                            alignItems: 'baseline',
                                        }}
                                    >
                                        <span className="t-money plan-price">
                                            {price(plan)}
                                        </span>
                                        {!plan.customPricing && (
                                            <span className="t-caption plan-dim">
                                                {yearly ? '/ year' : '/ month'}
                                            </span>
                                        )}
                                    </div>
                                    <span className="t-caption plan-dim">
                                        {note(plan)}
                                    </span>
                                </div>
                                <Link
                                    href={href(plan)}
                                    className={buttonClass({
                                        size: 'lg',
                                        variant: plan.popular
                                            ? 'primary'
                                            : plan.customPricing
                                              ? 'secondary'
                                              : 'muted',
                                    })}
                                >
                                    {plan.customPricing
                                        ? 'Talk to us'
                                        : 'Start free trial'}
                                </Link>
                                {card && (
                                    <div className="plan-points">
                                        <span className="t-label-strong">
                                            {card.lead}
                                        </span>
                                        <CheckList
                                            items={card.points}
                                            inherit
                                        />
                                    </div>
                                )}
                            </RevealItem>
                        );
                    })}
                </RevealGroup>
                <p
                    className="t-caption t-muted"
                    style={{ textAlign: 'center', margin: '18px 0 0' }}
                >
                    {currency === 'inr'
                        ? 'Rupee prices are before GST. Yearly plans are paid up front.'
                        : 'Prices in US dollars. Local taxes may apply. Yearly plans are paid up front.'}
                </p>
            </section>

            <section
                className="lp-in"
                style={{ paddingTop: 88 }}
                aria-labelledby="cmp-h"
            >
                <h2
                    id="cmp-h"
                    className="lp-h2 lp-h2--sm"
                    style={{ marginBottom: 20 }}
                >
                    Compare every plan
                </h2>
                <Reveal className="cmp-scroll" distance={32}>
                    <div
                        className="cmp-table"
                        role="table"
                        aria-label="Plan comparison"
                    >
                        <div className="cmp cmp-head" role="row">
                            <span
                                role="columnheader"
                                className="t-label t-muted"
                            >
                                Feature
                            </span>
                            {plans.map((plan) => (
                                <span
                                    key={plan.key}
                                    role="columnheader"
                                    className={cn(plan.popular && 'col-pop')}
                                >
                                    <span className="t-label-strong">
                                        {plan.name}
                                    </span>
                                    <span className="t-money-sm t-muted">
                                        {plan.customPricing
                                            ? 'Custom'
                                            : `${price(plan)}${yearly ? '/yr' : '/mo'}`}
                                    </span>
                                </span>
                            ))}
                        </div>
                        {ROWS.map((row) =>
                            'group' in row ? (
                                // The group row keeps the grid, so the Growth band
                                // runs unbroken through it (W7).
                                <div
                                    key={row.group}
                                    className="cmp cmp-group-row"
                                    role="row"
                                >
                                    <span
                                        role="rowheader"
                                        className="cmp-group"
                                    >
                                        {row.group}
                                    </span>
                                    {plans.map((plan) => (
                                        <span
                                            key={plan.key}
                                            aria-hidden="true"
                                            className={cn(
                                                plan.popular && 'col-pop',
                                            )}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <div key={row.label} className="cmp" role="row">
                                    <span role="rowheader" className="t-body">
                                        {row.label}
                                    </span>
                                    {plans.map((plan) => (
                                        <span
                                            key={plan.key}
                                            role="cell"
                                            className={cn(
                                                plan.popular && 'col-pop',
                                            )}
                                        >
                                            {'limit' in row ? (
                                                <span className="t-money-sm">
                                                    {limitLabel(
                                                        plan,
                                                        row.limit,
                                                    )}
                                                </span>
                                            ) : (
                                                <Included
                                                    yes={
                                                        'all' in row ||
                                                        plan.features.includes(
                                                            row.feature,
                                                        )
                                                    }
                                                />
                                            )}
                                        </span>
                                    ))}
                                </div>
                            ),
                        )}
                    </div>
                </Reveal>
            </section>

            <section
                className="lp-in lp-faq-wrap"
                style={{ paddingTop: 88 }}
                aria-labelledby="fq-h"
            >
                <div>
                    <h2 id="fq-h" className="lp-h2 lp-h2--sm">
                        Billing questions
                    </h2>
                    <p
                        className="t-body t-muted"
                        style={{
                            margin: '14px 0 0',
                            fontSize: 15,
                            lineHeight: '23px',
                        }}
                    >
                        Something else?{' '}
                        <Link href="/contact?topic=sales" className="cf-link">
                            Talk to us
                        </Link>
                        .
                    </p>
                </div>
                <Reveal delay={0.1}>
                    <FaqList faqs={FAQS} size="sm" />
                </Reveal>
            </section>

            <section
                className="lp-in"
                style={{ paddingTop: 88, paddingBottom: 64 }}
                aria-label="Get started"
            >
                <Reveal
                    className="cf-card--graphite lp-dark-cta"
                    distance={32}
                    fromScale={0.98}
                >
                    <div>
                        <h2 className="lp-h2 lp-on-dark lp-dark-cta-title">
                            Not sure which plan?
                        </h2>
                        <p
                            className="t-body lp-on-dark-muted"
                            style={{ margin: '10px 0 0' }}
                        >
                            Start on Growth. You can move up or down at any
                            time.
                        </p>
                    </div>
                    <div
                        className="cf-row"
                        style={{ gap: 10, flexWrap: 'wrap' }}
                    >
                        <ButtonLink
                            href="/register?plan=growth"
                            variant="lime"
                            size="lg"
                        >
                            Start free trial
                        </ButtonLink>
                        <ButtonLink
                            href="/contact?topic=demo"
                            variant="onDark"
                            size="lg"
                        >
                            Book a demo
                        </ButtonLink>
                    </div>
                </Reveal>
            </section>
        </>
    );
}
