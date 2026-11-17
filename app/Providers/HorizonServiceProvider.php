<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon (D95): the queue dashboard at /horizon is for platform admins only (signed in on
 * the `platform` guard at /admin), in every environment, never for tenant users. Long-wait
 * alerts go to HORIZON_NOTIFY_EMAIL when it is set.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $email = config('services.horizon.notify_email');

        if (is_string($email) && $email !== '') {
            Horizon::routeMailNotificationsTo($email);
        }
    }

    protected function authorization(): void
    {
        Horizon::auth(fn (Request $request): bool => Auth::guard('platform')->check());
    }

    protected function gate(): void
    {
        // Access is decided by authorization() above (the platform guard), not a tenant gate.
    }
}
