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
