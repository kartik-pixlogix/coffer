<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\DisableTwoFactorAuthentication;
use App\Actions\Fortify\EnableTwoFactorAuthentication;
use App\Actions\Fortify\GenerateNewRecoveryCodes;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Workspaces\CreateWorkspace;
use App\Auth\LoginLockout;
use App\Auth\RecoveryCodes;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Responses\FailedTwoFactorSignIn;
use App\Http\Responses\LockoutResponse;
use App\Http\Responses\SignedInResponse;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactor;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactor;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes as FortifyGenerateRecoveryCodes;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Contracts\LockoutResponse as LockoutResponseContract;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 5 failed tries lock sign-in for 15 minutes (spec §18).
        $this->app->bind(LoginRateLimiter::class, LoginLockout::class);
        $this->app->singleton(LockoutResponseContract::class, LockoutResponse::class);
        // G10, G13 (D113): sign-in lands on a page (the one asked for, or the role's first), never a file.
        $this->app->singleton(LoginResponse::class, SignedInResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, SignedInResponse::class);
        // A refused 2FA code says why: wrong, just used, or an expired sign-in.
        $this->app->singleton(FailedTwoFactorLoginResponse::class, FailedTwoFactorSignIn::class);
        // Admins can't turn 2FA off (spec §23).
        $this->app->bind(FortifyDisableTwoFactor::class, DisableTwoFactorAuthentication::class);
        // Ten XXXX-XXXX recovery codes, as on Profile (spec §17).
        $this->app->bind(FortifyEnableTwoFactor::class, EnableTwoFactorAuthentication::class);
        $this->app->bind(FortifyGenerateRecoveryCodes::class, GenerateNewRecoveryCodes::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        // A replaced code (one used at sign-in) keeps the same XXXX-XXXX shape.
        Fortify::generateRecoveryCodesUsing(fn () => RecoveryCodes::one());

        // Fortify's pipeline, with the Coffer lockout and the trusted-device 2FA skip.
        Fortify::authenticateThrough(fn () => array_filter([
            EnsureLoginIsNotThrottled::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'googleEnabled' => GoogleController::enabled(),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
            'email' => $request->user()?->email,
            // R4 (D119): locally with MAIL_MAILER=log the link is in the log, not an inbox.
            'mailLog' => app()->isLocal() && config('mail.default') === 'log',
        ]));

        // A Pricing CTA links here with ?plan= (D83): remembered for the Google path too.
        Fortify::registerView(function (Request $request) {
            $plan = CreateWorkspace::trialPlanKey($request->query('plan'));

            if ($plan !== null) {
                $request->session()->put(CreateWorkspace::SIGNUP_PLAN, $plan);
            }

            // W3 (D119): the form names the plan ("Starter plan · 14-day trial").
            $key = $plan ?? $request->session()->get(CreateWorkspace::SIGNUP_PLAN);
            $chosen = is_string($key) ? Plan::query()->where('key', $key)->first(['key', 'name']) : null;

            return Inertia::render('auth/register', [
                'googleEnabled' => GoogleController::enabled(),
                'plan' => $chosen === null ? null : ['key' => $chosen->key, 'name' => $chosen->name],
            ]);
        });

        Fortify::twoFactorChallengeView(function (Request $request) {
            $user = User::query()->whereKey($request->session()->get('login.id'))->first();

            return Inertia::render('auth/two-factor-challenge', [
                'email' => $user?->email,
            ]);
        });

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Phase 9 (D93): Fortify's sign-up, reset-link, reset and confirm-password posts have no
        // limiter of their own. Ten a minute per IP, and five per email address for the reset link.
        RateLimiter::for('auth-forms', fn (Request $request): array => [
            Limit::perMinute(10)->by('auth-forms:'.$request->ip()),
            Limit::perMinute(5)->by('auth-forms:'.strtolower((string) $request->input('email')).'|'.$request->path()),
        ]);

        $this->app->booted(function (): void {
            Route::getRoutes()->refreshNameLookups();

            foreach (['register.store', 'password.email', 'password.update', 'password.confirm.store'] as $name) {
                Route::getRoutes()->getByName($name)?->middleware('throttle:auth-forms');
            }
        });
    }
}
