<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Trust this device for 30 days" (spec §17). The browser keeps a random token in an
 * encrypted, HTTP-only cookie; the server keeps its hash. A trusted browser skips the
 * 2FA code at sign-in until the device expires or is forgotten from Profile.
 */
final class TrustedDevices
{
    public function trust(User $user, Request $request): void
    {
        $token = Str::random(64);

        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'label' => DeviceLabel::from($request->userAgent()),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(TrustedDevice::DAYS),
        ]);

        Cookie::queue(Cookie::make(
            TrustedDevice::COOKIE,
            $user->id.'|'.$token,
            TrustedDevice::DAYS * 24 * 60,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    /** Whether this browser is a live trusted device of `$user`; refreshes its last use. */
    public function recognises(User $user, Request $request): bool
    {
        $cookie = $request->cookie(TrustedDevice::COOKIE);

        if (! is_string($cookie) || ! str_contains($cookie, '|')) {
            return false;
        }

        [$userId, $token] = explode('|', $cookie, 2);

        if ((int) $userId !== $user->id) {
            return false;
        }

        $device = $user->trustedDevices()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        $device?->forceFill(['last_used_at' => now(), 'ip_address' => $request->ip()])->save();

        return $device !== null;
    }

    /** Forget every trusted device (Profile, password change, 2FA reset). */
    public function forgetAll(User $user): void
    {
        $user->trustedDevices()->delete();
        Cookie::queue(Cookie::forget(TrustedDevice::COOKIE));
    }
}
