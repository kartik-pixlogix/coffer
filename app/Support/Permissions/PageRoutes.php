<?php

declare(strict_types=1);

namespace App\Support\Permissions;

use Illuminate\Support\Facades\Route;

/**
 * Which route opens each matrix page. Pages whose module hasn't shipped have no route
 * yet; they are skipped when choosing a landing page or listing "What you can open".
 */
final class PageRoutes
{
    /** @var array<string, string> page key → route name */
    private const array ROUTES = [
        'dashboard' => 'dashboard',
        'partners' => 'partners.index',
        'team' => 'team.index',
        'timesheets' => 'timesheets.index',
        'income' => 'income.index',
        'expenses' => 'expenses.index',
        'capital_accounts' => 'capital-accounts.index',
        'partner_loans' => 'partner-loans.index',
        'settlements' => 'settlements.index',
        'reports' => 'reports.index',
        'trial_balance' => 'reports.trial-balance',
        'balance_sheet' => 'reports.balance-sheet',
        'cash_flow' => 'reports.cash-flow',
        'chart_of_accounts' => 'reports.chart-of-accounts',
        'tax' => 'tax.index',
        'journal' => 'reports.journal',
        'audit_trail' => 'audit.index',
        'vault' => 'vault.index',
        'settings' => 'settings.company',
        'billing' => 'billing.index',
    ];

    public static function url(string $page): ?string
    {
        $name = self::ROUTES[$page] ?? null;

        return $name !== null && Route::has($name) ? route($name) : null;
    }
}
