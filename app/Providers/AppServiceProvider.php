<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Dashboard\DashboardVaultPanel;
use App\Services\Dashboard\VaultExpiringPanel;
use App\Services\Team\SalaryRecalc;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultKeys;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (and per queued job), reset between them.
        $this->app->scoped(CurrentCompany::class);
        $this->app->scoped(SalaryRecalc::class);

        // Unwrapped vault keys and access caches live for one request or job.
        $this->app->scoped(VaultKeys::class);
        $this->app->scoped(VaultAccess::class);

        // The dashboard's "Credentials expiring soon" (D64).
        $this->app->bind(DashboardVaultPanel::class, VaultExpiringPanel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimits();
    }

    /** Spec §14.3: reveals, copies, one-time codes and downloads, 30 a minute per user. */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('vault-reveal', fn (Request $request): Limit => Limit::perMinute((int) config('vault.reveals_per_minute'))
            ->by('vault-reveal:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Spec §17: at least 12 characters (a short sentence works); breached passwords are refused in production.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(12),
        );
    }

    /**
     * Page and action abilities (`page.*`, `action.*`) come from the current company's
     * permission matrix. Everything else falls through to policies.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (str_starts_with($ability, 'page.') || str_starts_with($ability, 'action.')) {
                return app(Access::class)->allows($user, $ability);
            }

            return null;
        });
    }
}
