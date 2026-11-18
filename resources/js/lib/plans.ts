import { formatIndianNumber, formatInr } from '@/lib/format';
import type { BillingCurrency, PlanOption } from '@/types';

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

/** The largest yearly saving any plan offers in a currency ("Save 17%"), or null. */
export function bestYearlySaving(
    plans: PlanOption[],
    currency: BillingCurrency,
): number | null {
    const savings = plans
        .map((plan) => plan.yearlySaving[currency])
        .filter((saving): saving is number => saving !== null && saving > 0);

    return savings.length > 0 ? Math.max(...savings) : null;
}

export type LimitKey = keyof PlanOption['limits'];

/**
 * A limit cell on Pricing (Pricing.dc.html): the number, "Unlimited", or "Custom" for a
 * custom-priced plan's people and companies (they're set in the contract).
 */
export function limitLabel(plan: PlanOption, key: LimitKey): string {
    const value = plan.limits[key];

    if (value !== null) {
        return formatIndianNumber(value);
    }

    return plan.customPricing && key !== 'vaultItems' ? 'Custom' : 'Unlimited';
}

/**
 * What each plan includes (the Pricing.dc.html cards; Landing shows the first few).
 * Prices and limits come from the plans table.
 */
export const PLAN_CARD: Record<string, { lead: string; points: string[] }> = {
    starter: {
        lead: 'What you get',
        points: [
            'A single company',
            'Partners and equity',
            'Timesheets, income and expenses',
            'Reports with PDF and Excel export',
            'Personal vault with 2FA codes',
        ],
    },
    growth: {
        lead: 'Everything in Starter, plus',
        points: [
            'Several companies and joint ventures',
            'Settlements, capital accounts and loans',
            'Bulk import from spreadsheets',
            'Vault sharing and health score',
        ],
    },
    business: {
        lead: 'Everything in Growth, plus',
        points: [
            'Custom roles and permissions',
            'Vault audit log and policies',
            'Export controls',
            'Priority support',
        ],
    },
    enterprise: {
        lead: 'Everything in Business, plus',
        points: [
            'Single sign-on with SAML',
            'Custom limits and contract',
            'Onboarding help',
            'Invoicing by bank transfer',
        ],
    },
};
