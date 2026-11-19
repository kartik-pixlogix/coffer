<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TwoFactorCodes;
use App\Services\Platform\Impersonator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account-security actions (D110), for Fortify's routes as well as ours, by route name:
 *
 * - **S2:** while a platform admin impersonates, none of them run: password, email,
 *   2FA, recovery codes, sessions, trusted devices, data export, deleting the account or
 *   workspace. The page says why; the server refuses either way.
 * - **P9:** turning 2FA off, making new recovery codes and replacing the authenticator
 *   need the password or a current 2FA code in the same request (`confirmation`), not
 *   just a password confirmed earlier in the session.
 */
final class GuardAccountSecurity
{
    public const string IMPERSONATING = 'Account security is off while a platform admin is viewing as this person.';

    /** @var list<string> */
    public const array SECURITY_ROUTES = [
        'profile.update', 'profile.password.update', 'user-password.update', 'user-profile-information.update',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code', 'two-factor.secret-key',
        'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
        'profile.two-factor.start', 'profile.two-factor.replace',
        'profile.sessions.destroy', 'profile.sessions.destroy-others', 'profile.trusted-devices.destroy',
        'privacy.exports.store', 'privacy.exports.download', 'privacy.account.destroy', 'privacy.workspaces.destroy',
        'password.confirm.store',
    ];

    /** @var list<string> */
    public const array CONFIRM_ROUTES = ['two-factor.disable', 'two-factor.regenerate-recovery-codes', 'profile.two-factor.replace'];

    public function __construct(private readonly Impersonator $impersonator, private readonly TwoFactorCodes $codes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if (! is_string($name) || ! in_array($name, self::SECURITY_ROUTES, true)) {
            return $next($request);
        }

        if ($this->impersonator->isImpersonating($request)) {
            activity('platform')
                ->withProperties(['route' => $name, 'impersonation' => $this->impersonator->current($request)['id'] ?? null])
                ->log('account security blocked while impersonating');

            if ($request->expectsJson() && $request->header('X-Inertia') === null) {
                return response()->json(['message' => self::IMPERSONATING], 403);
            }

            if ($request->isMethod('GET')) {
                abort(403, self::IMPERSONATING);
            }

            Inertia::flash('toast', ['type' => 'warning', 'message' => self::IMPERSONATING]);

            return back();
        }

        $user = $request->user();

        if (in_array($name, self::CONFIRM_ROUTES, true) && $user instanceof User && $user->hasTwoFactorEnabled()) {
            $this->confirm($request, $user);
        }

        return $next($request);
    }

    /** The password, or a 6-digit code from the authenticator app. */
    private function confirm(Request $request, User $user): void
    {
        $given = trim((string) $request->input('confirmation', ''));

        if ($given === '') {
            throw ValidationException::withMessages(['confirmation' => 'Enter your password or a 6-digit code to confirm.']);
        }

        if (preg_match('/^\d{6}$/', $given) === 1) {
            $result = $this->codes->check($user, $given, allowRecovery: false);

            if ($result === TwoFactorCodes::VALID) {
                return;
            }

            throw ValidationException::withMessages(['confirmation' => $result === TwoFactorCodes::REPLAYED ? TwoFactorCodes::REPLAY_MESSAGE : 'That code didn’t match. Codes change every 30 seconds.']);
        }

        if (! Hash::check($given, (string) $user->password)) {
            throw ValidationException::withMessages(['confirmation' => 'That password didn’t work.']);
        }
    }
}
