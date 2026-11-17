<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Platform\PlatformMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Platform metrics on the /admin dashboard (spec §22, D87). */
final class PlatformStats extends StatsOverviewWidget
{
    protected ?string $heading = 'Platform';

    protected ?string $pollingInterval = null;

    protected static ?bool $isLazyLoaded = false;

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $m = app(PlatformMetrics::class)->summary();

        $mrr = PlatformMetrics::money($m['mrr']['inr'] ?? 0, 'inr');

        if (($m['mrr']['usd'] ?? 0) > 0) {
            $mrr .= ' + '.PlatformMetrics::money($m['mrr']['usd'], 'usd');
        }

        return [
            Stat::make('MRR', $mrr)
                ->description("{$m['paying']} paying workspaces, from plan prices")
                ->color('success'),
            Stat::make('Workspaces', (string) $m['workspaces'])
                ->description("{$m['companies']} companies · {$m['users']} users · {$m['suspended']} suspended"),
            Stat::make('Active trials', (string) $m['trials'])
                ->description('No-card trials still running')
                ->color('warning'),
            Stat::make('Signups, last 30 days', (string) $m['signups30'])
                ->description("{$m['signupsPrev30']} in the 30 days before"),
            Stat::make('Churn, last 30 days', (string) $m['churn30'])
                ->description('Subscriptions that ended')
                ->color($m['churn30'] > 0 ? 'danger' : 'gray'),
        ];
    }
}
