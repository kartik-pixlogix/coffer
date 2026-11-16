<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Auth\TrustedDevices;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/** "Trust this device for 30 days" ticked at the 2FA challenge. */
final class RememberTrustedDevice
{
    public function __construct(private readonly TrustedDevices $devices) {}

    public function handle(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $request = request();

        if ($request->boolean('trust_device')) {
            $this->devices->trust($event->user, $request);
        }
    }
}
