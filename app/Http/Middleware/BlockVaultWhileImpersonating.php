<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Platform\Impersonator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform admin impersonating a tenant user (D86) never reaches vault secrets: unlock,
 * reveal, copy, one-time codes, attachment downloads, export, import and writing items are
 * refused (403) for the whole impersonation.
 */
final class BlockVaultWhileImpersonating
{
    public const string MESSAGE = 'Vault secrets are off while a platform admin is impersonating.';

    public function __construct(private readonly Impersonator $impersonator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonator->isImpersonating($request)) {
            activity('platform')
                ->withProperties(['route' => $request->route()?->getName(), 'impersonation' => $this->impersonator->current($request)['id'] ?? null])
                ->log('vault access blocked while impersonating');

            abort(403, self::MESSAGE);
        }

        return $next($request);
    }
}
