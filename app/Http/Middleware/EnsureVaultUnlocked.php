<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Vault\VaultSession;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vault actions that change who reaches what (sharing, decisions, policies, import,
 * export) need 2FA on the account and the vault unlocked in this session (spec §14.3,
 * D69). JSON callers get 423 like the reveal endpoints; page forms go back with a toast.
 * Each request slides the idle timer.
 */
final class EnsureVaultUnlocked
{
    public function __construct(private readonly VaultSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            abort(403, 'Turn on two-factor sign-in to use the vault.');
        }

        if (! $this->session->isUnlocked()) {
            if ($request->expectsJson() && $request->header('X-Inertia') === null) {
                return response()->json(['message' => 'The vault locked. Unlock it to continue.', 'locked' => true], 423);
            }

            Inertia::flash('toast', ['type' => 'warning', 'message' => 'The vault locked. Unlock it to continue.']);

            return back();
        }

        $this->session->touch();

        return $next($request);
    }
}
