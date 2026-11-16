<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Auth\LoginLockout;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Fortify;

/** Sign-in while locked (5 failed tries): say when it opens again. */
final class LockoutResponse implements LockoutResponseContract
{
    public function __construct(private readonly LoginLockout $limiter) {}

    public function toResponse($request)
    {
        $minutes = max(1, (int) ceil($this->limiter->availableIn($request) / 60));
        $when = $minutes === 1 ? '1 minute' : "{$minutes} minutes";

        throw ValidationException::withMessages([
            Fortify::username() => ["Too many tries, so sign-in is locked. Try again in {$when}, or reset your password."],
        ])->status(429);
    }
}
