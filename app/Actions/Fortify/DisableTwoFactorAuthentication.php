<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\CompanyRole;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisable;

/**
 * Fortify's "turn off 2FA" with spec §23: Admins keep it on. Cancelling a setup that was
 * never confirmed is always allowed.
 */
class DisableTwoFactorAuthentication extends FortifyDisable
{
    public function __invoke($user)
    {
        if ($user instanceof User && $user->hasTwoFactorEnabled() && self::isRequiredFor($user)) {
            throw ValidationException::withMessages(['two_factor' => 'You’re an Admin, so 2FA stays on.']);
        }

        parent::__invoke($user);
    }

    public static function isRequiredFor(User $user): bool
    {
        return Membership::query()->where('user_id', $user->id)->where('role', CompanyRole::Admin->value)->exists();
    }
}
