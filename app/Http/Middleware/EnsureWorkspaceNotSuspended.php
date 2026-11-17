<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Platform\Impersonator;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended workspace (spec §22, D86) can't be used: every page of its companies shows
 * "This workspace is suspended" (423) instead. Signing out, switching to a company in
 * another workspace, the profile and ending an impersonation stay open; a platform admin
 * impersonating for support still gets through.
 */
final class EnsureWorkspaceNotSuspended
{
    /** Route names that stay open while suspended. */
    private const array OPEN = [
        'logout', 'companies.switch', 'impersonation.stop', 'theme.update',
        'profile.*', 'user-password.*', 'session.*', 'verification.*',
    ];

    public function __construct(private readonly CurrentCompany $current, private readonly Impersonator $impersonator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->current->get();

        if (! $request->user() instanceof User || $company === null || ! $company->workspace->isSuspended()) {
            return $next($request);
        }

        if ($request->routeIs(...self::OPEN) || $this->impersonator->isImpersonating($request)) {
            return $next($request);
        }

        $message = 'This workspace is suspended. Contact Coffer support to restore access.';

        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => $message, 'suspended' => true], 423);
        }

        /** @var User $user */
        $user = $request->user();
        $others = $user->companies()->with('workspace:id,name,suspended_at')->get()
            ->filter(fn ($c) => $c->workspace->suspended_at === null)
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'workspace' => $c->workspace->name])
            ->values();

        return Inertia::render('errors/suspended', [
            'workspace' => $company->workspace->name,
            'otherCompanies' => $others,
        ])->toResponse($request)->setStatusCode(423);
    }
}
