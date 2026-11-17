<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanLimit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscription plan (spec §20, D80): prices in paise / cents per cycle and currency, the
 * soft limits (null = unlimited) and feature flags. Edited in the super-admin panel; the
 * seeder writes placeholder values.
 *
 * @property int $id
 * @property string $key starter | growth | business | enterprise
 * @property string $name
 * @property string|null $tagline
 * @property int $position
 * @property bool $custom_pricing
 * @property bool $popular
 * @property int|null $price_monthly_inr paise
 * @property int|null $price_yearly_inr paise
 * @property int|null $price_monthly_usd cents
 * @property int|null $price_yearly_usd cents
 * @property int|null $max_companies
 * @property int|null $max_users
 * @property int|null $max_vault_items
 * @property int|null $max_storage_mb
 * @property list<string>|null $features
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'key', 'name', 'tagline', 'position', 'custom_pricing', 'popular',
    'price_monthly_inr', 'price_yearly_inr', 'price_monthly_usd', 'price_yearly_usd',
    'max_companies', 'max_users', 'max_vault_items', 'max_storage_mb', 'features',
])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'custom_pricing' => 'boolean',
            'popular' => 'boolean',
            'features' => 'array',
        ];
    }

    /** Price in the smallest unit (paise or cents), or null when it isn't sold that way. */
    public function price(string $cycle, string $currency): ?int
    {
        $column = 'price_'.($cycle === 'yearly' ? 'yearly' : 'monthly').'_'.($currency === 'usd' ? 'usd' : 'inr');
        $value = $this->getAttribute($column);

        return is_int($value) ? $value : null;
    }

    /** "Save 17%": what a year costs against twelve months, rounded down. */
    public function yearlySavingPercent(string $currency = 'inr'): ?int
    {
        $monthly = $this->price('monthly', $currency);
        $yearly = $this->price('yearly', $currency);

        if ($monthly === null || $yearly === null || $monthly === 0) {
            return null;
        }

        return max(0, (int) floor(100 - ($yearly * 100) / ($monthly * 12)));
    }

    public function limit(PlanLimit $limit): ?int
    {
        $value = $this->getAttribute($limit->column());

        return is_int($value) ? $value : null;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }

    /** The Stripe price id for a cycle and currency (config/billing.php, from env). */
    public function stripePrice(string $cycle, string $currency): ?string
    {
        $id = config("billing.stripe.prices.{$this->key}.{$cycle}.{$currency}");

        return is_string($id) && $id !== '' ? $id : null;
    }
}
