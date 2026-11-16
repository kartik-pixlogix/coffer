<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\LoginLockout;
use App\Auth\TrustedDevices;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * The session-expired dialog (StateSession.dc.html, D30): sign back in without leaving
 * the page, so unsaved form state survives. JSON only. Password first, then the 2FA
 * code when the account has 2FA (unless this browser is trusted). The same lockout as
 * the sign-in page applies.
 */
final class ReauthController extends Controller
{
    private const string PENDING = 'reauth.id';

    /** A fresh CSRF token for the new session (the old one died with the session). */
    public function token(Request $request): JsonResponse
    {
        return response()->json(['token' => $request->session()->token()]);
    }

    public function password(Request $request, LoginLockout $lockout, TrustedDevices $devices): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($lockout->tooManyAttempts($request)) {
            $minutes = max(1, (int) ceil($lockout->availableIn($request) / 60));

            return response()->json(['errors' => ['password' => ["Too many tries, so sign-in is locked. Try again in {$minutes} minutes."]]], 429);
        }

        $user = User::query()->where('email', Str::lower($request->string('email')->value()))->first();

        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            $lockout->increment($request);

            return response()->json(['errors' => ['password' => ['That password didn’t work. Try again or reset it.']]], 422);
        }

        $lockout->clear($request);

        if ($user->hasTwoFactorEnabled() && ! $devices->recognises($user, $request)) {
            $request->session()->put(self::PENDING, $user->id);

            return response()->json(['twoFactor' => true]);
        }

        return $this->signIn($request, $user);
    }

    public function code(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        $user = User::query()->whereKey($request->session()->get(self::PENDING))->first();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return response()->json(['errors' => ['code' => ['Sign in with your password first.']]], 422);
        }

        $key = 'reauth-code:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['errors' => ['code' => ['Too many codes. Wait a minute and try again.']]], 429);
        }

        $code = trim($request->string('code')->value());
        $valid = $provider->verify((string) decrypt((string) $user->two_factor_secret), $code);

        if (! $valid && in_array($code, $user->recoveryCodes(), true)) {
            $user->replaceRecoveryCode($code);
            $valid = true;
        }

        if (! $valid) {
            RateLimiter::hit($key, 60);

            return response()->json(['errors' => ['code' => ['That code didn’t match. Codes change every 30 seconds.']]], 422);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::PENDING);

        return $this->signIn($request, $user);
    }

    private function signIn(Request $request, User $user): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'token' => $request->session()->token()]);
    }
}
