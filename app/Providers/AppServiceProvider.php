<?php

namespace App\Providers;

use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
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
