<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Billable;

/**
 * The paying tenant (spec §2): owns the subscription, its companies and their users.
 * It is Cashier's billable (D80): the Stripe customer, its subscriptions and invoices.
 *
 * @property int $id
 * @property string $name
 * @property int $owner_id
 * @property int|null $plan_id the plan whose limits apply (the trial's, or the paid one); null = no plan set
 * @property string|null $billing_cycle monthly | yearly
 * @property string|null $billing_currency inr | usd
 * @property string|null $billing_email
 * @property string|null $billing_name
 * @property string|null $billing_gstin
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $past_due_since
 * @property CarbonImmutable|null $renews_at
 * @property CarbonImmutable|null $suspended_at set by a platform admin (D86): tenant access is blocked
 * @property string|null $suspended_reason
 * @property int|null $suspended_by platform_admins.id
 * @property-read User $owner
 * @property-read Plan|null $plan
 * @property-read Collection<int, Company> $companies
 */
#[Fillable(['name', 'owner_id', 'trial_ends_at', 'plan_id', 'billing_cycle', 'billing_currency', 'billing_email', 'billing_name', 'billing_gstin'])]
#[Hidden(['stripe_id', 'pm_type', 'pm_last_four'])]
class Workspace extends Model
{
    use Billable;

    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /** Length of the free trial a new sign-up gets (spec §18). */
    public const int TRIAL_DAYS = 14;

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'past_due_since' => 'datetime',
            'renews_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /** Suspended by a platform admin (spec §22, D86): its companies can't be opened. */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /** Stripe customer name: "Billed to" on the Billing page, else the workspace. */
    public function stripeName(): ?string
    {
        return $this->billing_name ?? $this->name;
    }

    /** Stripe customer email: the billing email, else the owner's. */
    public function stripeEmail(): ?string
    {
        return $this->billing_email ?? $this->owner->email;
    }

    /** @return array<string, string> */
    public function stripeMetadata(): array
    {
        return ['workspace_id' => (string) $this->id];
    }
}
