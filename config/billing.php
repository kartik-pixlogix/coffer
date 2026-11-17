<?php

declare(strict_types=1);

/*
| Billing and plans (spec §20, D80–D82). Prices and limits live in the `plans` table
| (placeholders seeded by PlanSeeder); Stripe price ids come from env so test-mode and
| live-mode keys never mix. See docs/BILLING_TESTING.md.
*/

return [
    // The payment gateway (D80). Only Stripe is built; Razorpay is a later option (spec §20).
    'gateway' => env('BILLING_GATEWAY', 'stripe'),

    // A new workspace's no-card trial, and the plan whose limits apply during it.
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),
    'trial_plan' => env('BILLING_TRIAL_PLAN', 'growth'),

    // After a trial ends unpaid, or a payment fails, Coffer stays usable this long before
    // the workspace turns read-only (D81). Data is never deleted.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Usage at or above this share of a limit shows "Near the limit" (Billing.dc.html).
    'near_limit' => 0.8,

    'currencies' => ['inr', 'usd'],
    'default_currency' => env('BILLING_CURRENCY', 'inr'),

    // Stripe Price ids per plan, cycle and currency (test-mode ids in local .env).
    'stripe' => [
        'prices' => [
            'starter' => [
                'monthly' => ['inr' => env('STRIPE_PRICE_STARTER_MONTHLY_INR'), 'usd' => env('STRIPE_PRICE_STARTER_MONTHLY_USD')],
                'yearly' => ['inr' => env('STRIPE_PRICE_STARTER_YEARLY_INR'), 'usd' => env('STRIPE_PRICE_STARTER_YEARLY_USD')],
            ],
            'growth' => [
                'monthly' => ['inr' => env('STRIPE_PRICE_GROWTH_MONTHLY_INR'), 'usd' => env('STRIPE_PRICE_GROWTH_MONTHLY_USD')],
                'yearly' => ['inr' => env('STRIPE_PRICE_GROWTH_YEARLY_INR'), 'usd' => env('STRIPE_PRICE_GROWTH_YEARLY_USD')],
            ],
            'business' => [
                'monthly' => ['inr' => env('STRIPE_PRICE_BUSINESS_MONTHLY_INR'), 'usd' => env('STRIPE_PRICE_BUSINESS_MONTHLY_USD')],
                'yearly' => ['inr' => env('STRIPE_PRICE_BUSINESS_YEARLY_INR'), 'usd' => env('STRIPE_PRICE_BUSINESS_YEARLY_USD')],
            ],
        ],
    ],
];
