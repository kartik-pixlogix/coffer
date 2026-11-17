<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\Membership;
use App\Models\Workspace;
use App\Services\Billing\BillingState;
use App\Services\Billing\WorkspaceBilling;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Subscription;
use NumberFormatter;

/**
 * Platform metrics for the super-admin dashboard (spec §22, D87): workspaces, active
 * trials, paying workspaces, MRR from plan prices, signups and churn. Read-only: it reads
 * workspaces, plans and Cashier subscriptions, never a tenant's books.
 */
final class PlatformMetrics
{
    public function __construct(private readonly WorkspaceBilling $billing) {}

    /**
     * Monthly recurring revenue of one workspace in the smallest unit of its currency: the
     * plan's price for its cycle (a yearly price over 12, rounded down). Only paying
     * statuses count (active, past due, cancelled but still in the paid period).
     *
     * @return array{amount: int, currency: string}|null
     */
    public function mrrFor(Workspace $workspace): ?array
    {
        $state = $this->billing->for($workspace);

        if (! in_array($state->status, ['active', 'past_due', 'canceled'], true) || $state->plan === null || ! $state->hasSubscription()) {
            return null;
        }

        $price = $state->plan->price($state->cycle, $state->currency);

        if ($price === null) {
            return null;
        }

        return ['amount' => $state->cycle === 'yearly' ? intdiv($price, 12) : $price, 'currency' => $state->currency];
    }

    public function status(Workspace $workspace): BillingState
    {
        return $this->billing->for($workspace);
    }

    /**
     * @return array{workspaces: int, suspended: int, trials: int, paying: int, mrr: array<string, int>, signups30: int, signupsPrev30: int, churn30: int, companies: int, users: int}
     */
    public function summary(): array
    {
        $now = CarbonImmutable::now();
        $trials = 0;
        $paying = 0;
        $mrr = ['inr' => 0, 'usd' => 0];

        Workspace::query()->with('plan')->chunkById(200, function ($workspaces) use (&$trials, &$paying, &$mrr): void {
            foreach ($workspaces as $workspace) {
                $status = $this->billing->for($workspace)->status;
                $trials += $status === 'trial' ? 1 : 0;

                $row = $this->mrrFor($workspace);

                if ($row !== null) {
                    $paying++;
                    $mrr[$row['currency']] = ($mrr[$row['currency']] ?? 0) + $row['amount'];
                }

                $this->billing->forget($workspace);
            }
        });

        return [
            'workspaces' => Workspace::query()->count(),
            'suspended' => Workspace::query()->whereNotNull('suspended_at')->count(),
            'trials' => $trials,
            'paying' => $paying,
            'mrr' => $mrr,
            'signups30' => Workspace::query()->where('created_at', '>=', $now->subDays(30))->count(),
            'signupsPrev30' => Workspace::query()->whereBetween('created_at', [$now->subDays(60), $now->subDays(30)])->count(),
            'churn30' => Subscription::query()->whereNotNull('ends_at')->whereBetween('ends_at', [$now->subDays(30), $now])->count(),
            'companies' => Company::query()->count(),
            'users' => Membership::query()->distinct()->count('user_id'),
        ];
    }

    /** ₹12,34,567 or $1,234 from paise / cents, whole units (Indian grouping for rupees). */
    public static function money(int $amount, string $currency): string
    {
        $formatter = new NumberFormatter($currency === 'usd' ? 'en_US' : 'en_IN', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);

        return (string) $formatter->formatCurrency(intdiv($amount, 100), $currency === 'usd' ? 'USD' : 'INR');
    }
}
