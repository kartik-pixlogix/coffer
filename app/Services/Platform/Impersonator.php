<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Impersonation;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Platform-admin impersonation (spec §22, D86). Starting needs a reason and writes an
 * `impersonations` row plus a `platform` activity entry (who, whom, why, when, IP); the
 * tenant `web` guard then signs in as the user while the `platform` guard stays signed in.
 * The session marker drives the app's banner and blocks every vault secret path.
 */
final class Impersonator
{
    public const string SESSION = 'impersonation';

    /** Session keys a platform admin must not inherit from an earlier tenant session. */
    private const array TENANT_SESSION_KEYS = [
        CurrentCompany::SESSION_KEY,
        'vault.unlocked_at',
        'vault.active_at',
        'vault.verified_at',
        'vault.verified_spent',
        'auth.password_confirmed_at',
    ];

    public function start(PlatformAdmin $admin, User $user, string $reason, Request $request, ?Workspace $workspace = null): Impersonation
    {
        $reason = trim($reason);

        if (Str::length($reason) < 10) {
            throw new InvalidArgumentException('An impersonation needs a reason of at least 10 characters.');
        }

        if ($this->isImpersonating($request)) {
            $this->stop();
        }

        $impersonation = DB::transaction(function () use ($admin, $user, $reason, $request, $workspace): Impersonation {
            $impersonation = Impersonation::query()->create([
                'platform_admin_id' => $admin->id,
                'user_id' => $user->id,
                'workspace_id' => $workspace?->id,
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'started_at' => now(),
            ]);

            activity('platform')
                ->causedBy($admin)
                ->performedOn($user)
                ->withProperties(['impersonation_id' => $impersonation->id, 'workspace_id' => $workspace?->id, 'reason' => $reason, 'ip' => $request->ip()])
                ->log('impersonation started');

            return $impersonation;
        });

        session()->forget(self::TENANT_SESSION_KEYS);
        Auth::guard('web')->login($user);
        session()->put(self::SESSION, [
            'id' => $impersonation->id,
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
            'reason' => $reason,
        ]);

        return $impersonation;
    }

    /**
     * The running impersonation for this session, if the tenant user is still the one it
     * started as.
     *
     * @return array{id: int, admin_id: int, admin_name: string, user_id: int, workspace_id: int|null, reason: string}|null
     */
    public function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        /** @var array{id: int, admin_id: int, admin_name: string, user_id: int, workspace_id: int|null, reason: string}|null $marker */
        $marker = session()->get(self::SESSION);

        if (! is_array($marker) || Auth::guard('web')->id() !== $marker['user_id']) {
            return null;
        }

        return $marker;
    }

    public function isImpersonating(Request $request): bool
    {
        return $this->current($request) !== null;
    }

    /** Ends it: closes the row, logs it, signs the tenant guard out. The platform guard stays. */
    public function stop(bool $signOut = true): ?Impersonation
    {
        /** @var array{id?: int}|null $marker */
        $marker = session()->pull(self::SESSION);
        $impersonation = is_array($marker) && isset($marker['id']) ? Impersonation::query()->find($marker['id']) : null;

        if ($impersonation !== null && $impersonation->ended_at === null) {
            $impersonation->forceFill(['ended_at' => now()])->save();

            activity('platform')
                ->causedBy($impersonation->admin)
                ->performedOn($impersonation->user)
                ->withProperties(['impersonation_id' => $impersonation->id])
                ->log('impersonation ended');
        }

        if ($marker !== null && $signOut) {
            Auth::guard('web')->logout();
            session()->forget(self::TENANT_SESSION_KEYS);
        }

        return $impersonation;
    }
}
