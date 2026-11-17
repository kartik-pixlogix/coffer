<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\PlanLimitReached;
use App\Services\Billing\WorkspaceBilling;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only after the grace period (spec §20, D81): once a trial or a subscription has
 * ended unpaid and the grace days are over, company routes still show everything but
 * refuse changes (answered like a plan limit, so the Upgrade modal opens). Billing,
 * notifications, switching company and reading secrets keep working.
 */
final class EnsureWorkspaceWritable
{
    /** Route names that stay open when read-only (reads done by POST, and the way out). */
    private const array ALLOWED = [
        'billing.*', 'privacy.*', 'notifications.*', 'settings.notifications*', 'companies.switch', 'access-requests.*',
        'vault.unlock', 'vault.lock', 'vault.touch', 'vault.reuse', 'vault.items.reveal', 'vault.items.copy',
        'vault.items.totp', 'vault.items.favourite', 'vault.export.store', 'vault.health.check',
    ];

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly WorkspaceBilling $billing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->current->get();

        $route = $request->route();

        if ($company === null || $request->isMethodSafe() || $route === null
            || ! in_array('company', $route->gatherMiddleware(), true)
            || Str::is(self::ALLOWED, (string) $route->getName())) {
            return $next($request);
        }

        $state = $this->billing->for($company->workspace);

        if ($state->isReadOnly()) {
            $why = $state->status === 'past_due'
                ? 'Payments for '.$company->workspace->name.' are overdue, so it’s read-only for now.'
                : $company->workspace->name.'’s '.($company->workspace->subscriptions()->exists() ? 'plan' : 'trial').' has ended, so it’s read-only for now.';

            throw PlanLimitReached::readOnly($state->plan->name ?? 'your plan', $why);
        }

        return $next($request);
    }
}
