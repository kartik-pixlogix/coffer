<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The four plans of Pricing.dc.html (spec §20, D80) with PLACEHOLDER prices and limits —
 * the owner sets the real ones in the super-admin plans editor [VERIFY]. Safe to re-run:
 * rows are matched by key and left alone once they exist, so edited prices survive.
 */
final class PlanSeeder extends Seeder
{
    /**
     * Feature flags, as in Pricing.dc.html's comparison table.
     *
     * @var array<string, list<string>>
     */
    public const array FEATURES = [
        'starter' => ['reports_export', 'vault_totp', 'email_support'],
        'growth' => ['reports_export', 'vault_totp', 'email_support', 'joint_ventures', 'settlements', 'capital_loans', 'statements', 'bulk_import', 'vault_sharing', 'vault_health'],
        'business' => ['reports_export', 'vault_totp', 'email_support', 'joint_ventures', 'settlements', 'capital_loans', 'statements', 'bulk_import', 'vault_sharing', 'vault_health', 'custom_roles', 'vault_audit', 'vault_policies', 'export_controls', 'priority_support'],
        'enterprise' => ['reports_export', 'vault_totp', 'email_support', 'joint_ventures', 'settlements', 'capital_loans', 'statements', 'bulk_import', 'vault_sharing', 'vault_health', 'custom_roles', 'vault_audit', 'vault_policies', 'export_controls', 'priority_support', 'sso', 'onboarding', 'bank_invoicing'],
    ];

    /** @var list<array<string, mixed>> */
    public const array PLANS = [
        [
            'key' => 'starter', 'name' => 'Starter', 'position' => 1, 'popular' => false, 'custom_pricing' => false,
            'tagline' => 'One company getting its books in order.',
            'price_monthly_inr' => 99_900, 'price_yearly_inr' => 9_99_000, 'price_monthly_usd' => 1_500, 'price_yearly_usd' => 15_000,
            'max_companies' => 1, 'max_users' => 3, 'max_vault_items' => 10, 'max_storage_mb' => 1_024,
        ],
        [
            'key' => 'growth', 'name' => 'Growth', 'position' => 2, 'popular' => true, 'custom_pricing' => false,
            'tagline' => 'A few companies, or a joint venture with sides.',
            'price_monthly_inr' => 2_49_900, 'price_yearly_inr' => 24_99_000, 'price_monthly_usd' => 3_500, 'price_yearly_usd' => 35_000,
            'max_companies' => 5, 'max_users' => 15, 'max_vault_items' => 25, 'max_storage_mb' => 10_240,
        ],
        [
            'key' => 'business', 'name' => 'Business', 'position' => 3, 'popular' => false, 'custom_pricing' => false,
            'tagline' => 'Groups that need roles, policies and an audit trail.',
            'price_monthly_inr' => 5_99_900, 'price_yearly_inr' => 59_99_000, 'price_monthly_usd' => 7_900, 'price_yearly_usd' => 79_000,
            'max_companies' => 15, 'max_users' => 50, 'max_vault_items' => null, 'max_storage_mb' => 51_200,
        ],
        [
            'key' => 'enterprise', 'name' => 'Enterprise', 'position' => 4, 'popular' => false, 'custom_pricing' => true,
            'tagline' => 'Larger groups with their own setup and contracts.',
            'price_monthly_inr' => null, 'price_yearly_inr' => null, 'price_monthly_usd' => null, 'price_yearly_usd' => null,
            'max_companies' => null, 'max_users' => null, 'max_vault_items' => null, 'max_storage_mb' => null,
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            Plan::query()->firstOrCreate(['key' => $plan['key']], [...$plan, 'features' => self::FEATURES[$plan['key']]]);
        }
    }
}
