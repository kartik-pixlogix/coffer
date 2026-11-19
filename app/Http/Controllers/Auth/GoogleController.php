<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Invites\AcceptInvite;
use App\Actions\Workspaces\CreateWorkspace;
use App\Auth\TrustedDevices;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use App\Support\Auth\IntendedUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Continue with Google" for sign-in, sign-up and accepting an invite (spec §2.3, §18).
 * Google must vouch for the email. 2FA still applies: a user with 2FA goes to the
 * code challenge unless this browser is trusted.
 */
final class GoogleController extends Controller
{
    private const string INTENT = 'google.intent';

    private const string INVITE = 'google.invite';

    public function redirect(Request $request): RedirectResponse|SymfonyRedirect
    {
        if (! self::enabled()) {
            return redirect()->route('login')->withErrors(['email' => 'Google sign-in isn’t set up yet. Use your email and password.']);
        }

        $intent = in_array($request->query('intent'), ['signup', 'invite'], true) ? $request->query('intent') : 'login';

        $request->session()->put(self::INTENT, $intent);
        $request->session()->put(self::INVITE, $intent === 'invite' ? $request->string('invite')->value() : null);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, CreateWorkspace $createWorkspace, AcceptInvite $acceptInvite, TrustedDevices $devices): RedirectResponse
    {
        $intent = $request->session()->pull(self::INTENT, 'login');
        $inviteToken = $request->session()->pull(self::INVITE);

        try {
            /** @var GoogleUser $google */
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return $this->fail($intent, $inviteToken, 'Google didn’t sign you in. Try again.');
        }

        $email = Str::lower((string) $google->getEmail());

        if ($email === '' || ($google->user['email_verified'] ?? false) !== true) {
            return $this->fail($intent, $inviteToken, 'Google didn’t confirm that email address.');
        }

        $user = User::query()->where('email', $email)->first();

        if ($intent === 'invite' && is_string($inviteToken)) {
            $invite = Invite::findByToken($inviteToken);

            if ($invite === null || ! $invite->isUsable()) {
                return redirect()->route('invites.show', $inviteToken);
            }

            if ($invite->email !== null && Str::lower($invite->email) !== $email) {
                return $this->fail($intent, $inviteToken, "This invite is for {$invite->email}. Sign in to Google with that address.");
            }

            $user ??= $this->createUser($google, $email);

            try {
                $acceptInvite->handle($invite, $user);
            } catch (ValidationException $e) {
                return redirect()->route('invites.show', $inviteToken)->withErrors($e->errors());
            }

            return $this->signIn($request, $user, $devices);
        }

        if ($user === null) {
            if ($intent !== 'signup') {
                return $this->fail($intent, null, "No Coffer account uses {$email}. Start a free trial, or ask your Admin for an invite.");
            }

            $plan = CreateWorkspace::trialPlanKey($request->session()->pull(CreateWorkspace::SIGNUP_PLAN));

            $user = DB::transaction(function () use ($google, $email, $createWorkspace, $plan): User {
                $user = $this->createUser($google, $email);
                $createWorkspace->handle($user, Str::before($user->name, ' ').'’s workspace', $plan);

                return $user;
            });
        }

        return $this->signIn($request, $user, $devices);
    }

    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function createUser(GoogleUser $google, string $email): User
    {
        $user = User::query()->create([
            'name' => $google->getName() ?: Str::before($email, '@'),
            'email' => $email,
            // Never used: they sign in with Google until they set a password from Profile.
            'password' => Str::random(64),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function signIn(Request $request, User $user, TrustedDevices $devices): RedirectResponse
    {
        if ($user->hasTwoFactorEnabled() && ! $devices->recognises($user, $request)) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->to(IntendedUrl::pull($request));
    }

    private function fail(string $intent, ?string $inviteToken, string $message): RedirectResponse
    {
        $target = match ($intent) {
            'signup' => redirect()->route('register'),
            'invite' => is_string($inviteToken) ? redirect()->route('invites.show', $inviteToken) : redirect()->route('login'),
            default => redirect()->route('login'),
        };

        return $target->withErrors(['email' => $message]);
    }
}
