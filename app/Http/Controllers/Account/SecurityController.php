<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Auth\DeviceLabel;
use App\Auth\TrustedDevices;
use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * The security parts of Profile (spec §17): photo, password, sessions, trusted devices,
 * and replacing the authenticator app (Fortify covers enable, confirm and codes).
 */
final class SecurityController extends Controller
{
    use PasswordValidationRules;

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4000,max_height=4000']], [
            'avatar.max' => 'Use an image under 2 MB.',
            'avatar.image' => 'Use a JPG, PNG or WebP image.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $old = $user->avatar_path;
        $path = $request->file('avatar')?->store('avatars', 'public');

        $user->forceFill(['avatar_path' => $path])->save();

        if ($old !== null) {
            Storage::disk('public')->delete($old);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Photo updated']);

        return back();
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return back();
    }

    /** Changing the password signs out every other session (spec §17). */
    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => 'That isn’t your current password.',
            'password.confirmed' => 'The two passwords don’t match.',
            'password.min' => 'Use at least 12 characters.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'password' => $request->string('password')->value(),
            'password_changed_at' => now(),
        ])->save();

        $signedOut = $this->signOutOthers($request, $user);

        activity('security')->performedOn($user)->causedBy($user)->log('changed their password');

        Inertia::flash('toast', ['type' => 'success', 'message' => $signedOut > 0 ? 'Password changed. Other sessions were signed out.' : 'Password changed']);

        return back();
    }

    /** Sign out one other session, identified by the hash the page was given. */
    public function destroySession(Request $request, string $session): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $row = $this->sessionTable()->where('user_id', $user->id)->get(['id', 'user_agent'])
            ->first(fn (object $s) => hash('sha256', (string) $s->id) === $session && $s->id !== $request->session()->getId());

        abort_if($row === null, 404);

        $this->sessionTable()->where('id', $row->id)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => DeviceLabel::from($row->user_agent).' signed out']);

        return back();
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->signOutOthers($request, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? 'Signed out of 1 other session' : "Signed out of {$count} other sessions"]);

        return back();
    }

    public function forgetTrustedDevices(Request $request, TrustedDevices $devices): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $devices->forgetAll($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trusted devices forgotten. Every device asks for a code again.']);

        return back();
    }

    /**
     * "Set up 2FA": behind password confirmation, so it carries on straight after the
     * confirm page. Only creates an unconfirmed secret, which changes nothing about
     * sign-in until a code confirms it.
     */
    public function startTwoFactor(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_secret === null) {
            $enable($user);
        }

        return redirect()->route('profile.edit');
    }

    /** "Replace authenticator": a new secret and codes to confirm with the new app. */
    public function replaceTwoFactor(Request $request, EnableTwoFactorAuthentication $enable): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $enable($user, force: true);
        $user->trustedDevices()->delete();

        return back();
    }

    private function signOutOthers(Request $request, User $user): int
    {
        $count = $this->sessionTable()->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        // Remember-me cookies on other devices stop working too.
        $user->setRememberToken(Str::random(60));
        $user->save();

        return $count;
    }

    private function sessionTable(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));
    }
}
