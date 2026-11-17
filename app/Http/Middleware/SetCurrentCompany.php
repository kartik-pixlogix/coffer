<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\Platform\Impersonator;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the company this request works in: the switcher's choice from the session,
 * else the user's first company. Only companies the user belongs to can be current.
 */
final class SetCurrentCompany
{
    /** How often `company_user.last_active_at` is refreshed. */
    private const int ACTIVITY_MINUTES = 5;

    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->resolve($request, $user);
        }

        return $next($request);
    }

    private function resolve(Request $request, User $user): void
    {
        $chosen = $request->session()->get(CurrentCompany::SESSION_KEY);

        /** @var Company|null $company */
        // The switcher's choice, else the company they were last active in.
        $company = (is_int($chosen) ? $user->companies()->whereKey($chosen)->first() : null)
            ?? $user->companies()->reorder()
                ->orderByRaw('case when company_user.last_active_at is null then 1 else 0 end')
                ->orderByPivot('last_active_at', 'desc')
                ->orderBy('companies.name')
                ->first();

        if ($company === null) {
            $request->session()->forget(CurrentCompany::SESSION_KEY);
            $this->current->set(null);

            return;
        }

        /** @var Membership $membership */
        $membership = $company->getRelation('membership');

        $request->session()->put(CurrentCompany::SESSION_KEY, $company->id);
        $this->current->set($company, $membership->role);

        // An impersonating platform admin (D86) leaves no trace on the member's activity.
        if (! $request->session()->has(Impersonator::SESSION) && ($membership->last_active_at === null || $membership->last_active_at->lt(now()->subMinutes(self::ACTIVITY_MINUTES)))) {
            $membership->forceFill(['last_active_at' => now()])->saveQuietly();
        }
    }
}
