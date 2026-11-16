<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\PageDeniedException;
use App\Support\Permissions\Catalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `page:<key>` on a route: the user's role in the current company must be allowed to
 * open that page (spec §2.1). Otherwise the "Permission denied" page renders.
 */
final class EnsurePageAllowed
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        if (! array_key_exists($page, Catalog::PAGES)) {
            throw new \InvalidArgumentException("Unknown page [{$page}].");
        }

        if (! $request->user()?->can(Catalog::pagePermission($page))) {
            throw new PageDeniedException($page);
        }

        return $next($request);
    }
}
