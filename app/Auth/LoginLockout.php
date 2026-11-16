<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

/**
 * Sign-in lockout (spec §18): 5 failed attempts per email and IP lock sign-in for
 * 15 minutes. Replaces Fortify's limiter, which locks for one minute only.
 */
class LoginLockout extends LoginRateLimiter
{
    public const int MAX_ATTEMPTS = 5;

    public const int LOCK_MINUTES = 15;

    /** After this many failures the error says how many tries are left. */
    public const int WARN_AFTER = 3;

    public function tooManyAttempts(Request $request)
    {
        return $this->limiter->tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS);
    }

    public function increment(Request $request)
    {
        $this->limiter->hit($this->throttleKey($request), self::LOCK_MINUTES * 60);
    }

    public function remaining(Request $request): int
    {
        return max(0, self::MAX_ATTEMPTS - (int) $this->attempts($request));
    }
}
