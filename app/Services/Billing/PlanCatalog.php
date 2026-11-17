<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;

/**
 * The plans as the front end reads them (Billing's Upgrade modal now; Pricing in part B):
 * prices per cycle and currency in the smallest unit, the yearly saving, limits (null =
 * unlimited), features, and whether Checkout can sell it yet (a Stripe price id is set).
 */
final class PlanCatalog
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values(Plan::query()->orderBy('position')->get()->map(fn (Plan $plan) => $this->row($plan))->all());
    }

    /** @return array<string, mixed> */
    public function row(Plan $plan): array
    {
        $prices = [];
        $purchasable = [];

        foreach (['monthly', 'yearly'] as $cycle) {
            foreach ((array) config('billing.currencies') as $currency) {
                $currency = (string) $currency;
                $prices[$cycle][$currency] = $plan->price($cycle, $currency);
                $purchasable[$cycle][$currency] = ! $plan->custom_pricing && $plan->stripePrice($cycle, $currency) !== null;
            }
        }

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'tagline' => $plan->tagline,
            'position' => $plan->position,
            'popular' => $plan->popular,
            'customPricing' => $plan->custom_pricing,
            'prices' => $prices,
            'purchasable' => $purchasable,
            'yearlySaving' => ['inr' => $plan->yearlySavingPercent('inr'), 'usd' => $plan->yearlySavingPercent('usd')],
            'limits' => [
                'companies' => $plan->max_companies,
                'users' => $plan->max_users,
                'vaultItems' => $plan->max_vault_items,
                'storageMb' => $plan->max_storage_mb,
            ],
            'features' => $plan->features ?? [],
        ];
    }
}
