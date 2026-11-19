<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Platform\Impersonator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/**
 * The app's Sign out (S3, D111). The `web` and `platform` guards share one session, so
 * Fortify's sign-out (which invalidates the session) also signed a platform admin out of
 * /admin. With the platform guard signed in, only the tenant guard signs out: the tenant
 * session keys are cleared and the CSRF token regenerated, the session itself is kept.
 * Bound in place of Fortify's controller, so `POST /logout` keeps its name and route.
 */
final class SignOutController extends AuthenticatedSessionController
{
    public function destroy(Request $request): LogoutResponse
    {
        if (! Auth::guard('platform')->check() || ! $request->hasSession()) {
            return parent::destroy($request);
        }

        Auth::guard('web')->logout();
        $request->session()->forget(Impersonator::TENANT_SESSION_KEYS);
        $request->session()->forget(Impersonator::SESSION);
        $request->session()->regenerate(destroy: true);
        $request->session()->regenerateToken();

        return app(LogoutResponse::class);
    }
}
