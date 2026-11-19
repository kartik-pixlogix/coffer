export type BillingCycle = 'monthly' | 'yearly';
export type BillingCurrency = 'inr' | 'usd';

/** App\Services\Billing\BillingState statuses (D80, D81). */
export type BillingStatusKey =
    | 'unmanaged'
    | 'trial'
    | 'active'
    | 'past_due'
    | 'canceled'
    | 'expired';

/** Shared prop `billingStatus`: the shell's banner. */
export type BillingStatus = {
    status: BillingStatusKey;
    plan: string | null;
    trialDaysLeft: number | null;
    readOnly: boolean;
    graceEndsAt: string | null;
    /** The viewer may open Billing (workspace owner / Admin). */
    canManage: boolean;
    owner: string;
};

/** Shared prop `planLimit`: "Near the limit" on the page that adds the thing (D82). */
export type PlanLimitBanner = {
    key: 'companies' | 'users' | 'vault_items' | 'storage';
    label: string;
    noun: string;
    used: number;
    limit: number;
    full: boolean;
};

type ByCycle<T> = Record<BillingCycle, Record<BillingCurrency, T>>;

/** App\Services\Billing\PlanCatalog row (also read by Pricing in part B). */
export type PlanOption = {
    key: string;
    name: string;
    tagline: string | null;
    position: number;
    popular: boolean;
    customPricing: boolean;
    /** Paise (INR) or cents (USD); null when not sold that way. */
    prices: ByCycle<number | null>;
    /** A Stripe price id is configured, so Checkout can sell it. */
    purchasable: ByCycle<boolean>;
    yearlySaving: Record<BillingCurrency, number | null>;
    limits: {
        companies: number | null;
        users: number | null;
        vaultItems: number | null;
        storageMb: number | null;
    };
    features: string[];
};

export type BillingCurrent = {
    plan: string | null;
    cycle: BillingCycle;
    currency: BillingCurrency;
    status: BillingStatusKey;
    hasSubscription: boolean;
};

export type UpgradeContext = {
    plans: PlanOption[];
    current: BillingCurrent;
    workspace: {
        name: string;
        companies: number;
        users: number;
        owner: string;
    };
    gatewayReady: boolean;
    /** Local only: say which keys to add (F6); customers see "Contact support". */
    gatewayHint: boolean;
};

export type UsageRow = {
    key: 'companies' | 'users' | 'vault_items' | 'storage';
    label: string;
    used: number;
    limit: number | null;
    detail: string;
};

export type InvoiceRow = {
    id: string;
    number: string;
    date: string;
    period: string;
    plan: string;
    amount: string;
    status: string;
};
