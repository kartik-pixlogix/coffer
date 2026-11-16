<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Auth\LoginLockout;
use App\Auth\TrustedDevices;
use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirect;
use Laravel\Fortify\Fortify;

/**
 * Fortify's 2FA step with two Coffer rules (spec §17, §18):
 * a trusted browser skips the code, and a failed password says how many tries are
 * left before the 15-minute lock.
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirect
{
    public function __construct(StatefulGuard $guard, LoginLockout $limiter, private readonly TrustedDevices $devices)
    {
        parent::__construct($guard, $limiter);
    }

    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->hasTwoFactorEnabled() && ! $this->devices->recognises($user, $request)) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }

    protected function throwFailedAuthenticationException($request)
    {
        $this->limiter->increment($request);

        /** @var LoginLockout $limiter */
        $limiter = $this->limiter;
        $left = $limiter->remaining($request);

        $message = 'That email and password don’t match.';

        if ($left === 0) {
            $message = 'That email and password don’t match. Sign-in is locked for 15 minutes.';
        } elseif ($limiter->attempts($request) >= LoginLockout::WARN_AFTER) {
            $message .= ' '.($left === 1 ? '1 try' : "{$left} tries").' left before a 15-minute lock.';
        }

        throw ValidationException::withMessages([
            Fortify::username() => [$message],
            'password' => ['Check your password and try again.'],
        ]);
    }
}
