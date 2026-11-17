<?php

namespace App\Providers;

use App\Jobs\CheckVaultBreaches;
use App\Jobs\CommitTimesheetImport;
use App\Jobs\CommitVaultImport;
use App\Jobs\GenerateReportExport;
use App\Jobs\ReencryptVaultData;
use App\Mail\ContactRequestReceived;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CofferNotification;
use App\Notifications\CompanyDatabaseChannel;
use App\Notifications\CompanyInvitation;
use App\Notifications\InviteRenewalRequested;
use App\Notifications\LedgerIntegrityFailed;
use App\Services\Billing\PaymentGateway;
use App\Services\Billing\StripeGateway;
use App\Services\Billing\WorkspaceBilling;
use App\Services\Dashboard\DashboardVaultPanel;
use App\Services\Dashboard\VaultExpiringPanel;
use App\Services\Platform\Impersonator;
use App\Services\Team\SalaryRecalc;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultKeys;
use App\Services\Vault\VaultSharing;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /** Every queue the app uses (D95), most urgent first. */
    public const array QUEUES = ['default', 'mail', 'exports', 'imports', 'vault'];

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
        $this->app->scoped(VaultSharing::class);

        // The dashboard's "Credentials expiring soon" (D64).
        $this->app->bind(DashboardVaultPanel::class, VaultExpiringPanel::class);

        // Notifications carry their company in a column (D78).
        $this->app->bind(DatabaseChannel::class, CompanyDatabaseChannel::class);

        // Billing (D80): the workspace is Cashier's customer; Stripe is the only gateway so far.
        Cashier::useCustomerModel(Workspace::class);
        $this->app->singleton(WorkspaceBilling::class);
        $this->app->bind(PaymentGateway::class, StripeGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimits();
        $this->configureQueues();

        // Server-side rendering is for the public site only (D83): search engines and link
        // previews need it there; app pages behind sign-in render in the browser as before.
        // Signing out of the app mid-impersonation still closes the impersonation record (D86).
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->guard === 'web' && session()->has(Impersonator::SESSION)) {
                app(Impersonator::class)->stop(signOut: false);
            }
        });

        Inertia::disableSsr(fn (Request $request): bool => ! $request->routeIs('welcome', 'marketing.*'));
    }

    /**
     * Queues (D95): default and mail for quick work, exports / imports / vault for slow work
     * on Horizon's "heavy" workers. Locally (database driver, no Horizon) `composer run dev`
     * listens on all of them. A failed job is logged at critical (D97) with its class only:
     * payloads can hold record ids and must not be copied into logs.
     */
    protected function configureQueues(): void
    {
        Queue::route([
            GenerateReportExport::class => 'exports',
            CommitTimesheetImport::class => 'imports',
            CommitVaultImport::class => 'imports',
            CheckVaultBreaches::class => 'vault',
            ReencryptVaultData::class => 'vault',
            CofferNotification::class => 'mail',
            CompanyInvitation::class => 'mail',
            InviteRenewalRequested::class => 'mail',
            LedgerIntegrityFailed::class => 'mail',
            ContactRequestReceived::class => 'mail',
        ]);

        Queue::failing(function (JobFailed $event): void {
            Log::critical('Queue job failed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'exception' => $event->exception::class,
            ]);
        });

        if (app()->runningInConsole() && config('queue.default') !== 'redis') {
            DevCommands::except('horizon');
            DevCommands::artisan('queue:listen --queue='.implode(',', self::QUEUES).' --tries=1 --timeout=0', 'queue');
        }
    }

    /** Spec §14.3: reveals, copies, one-time codes and downloads, 30 a minute per user. */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('vault-reveal', fn (Request $request): Limit => Limit::perMinute((int) config('vault.reveals_per_minute'))
            ->by('vault-reveal:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Part B (D72–D77): named so their counters don't mix with the plain `throttle:N,1` routes.
        foreach (['vault-share' => 60, 'vault-requests' => 10, 'vault-files' => 10] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($perMinute)
                ->by($name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }

        // The marketing Contact / Book a demo form (D84): a few an hour per IP, answered
        // with a form error rather than a bare 429 page.
        RateLimiter::for('contact', fn (Request $request): Limit => Limit::perHour((int) config('marketing.contact_per_hour'))
            ->by('contact:'.$request->ip())
            ->response(fn () => back()->withErrors(['form' => 'That’s a lot of messages from here. Try again in an hour, or email us.'])->withInput()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Phase 9 (D92): a lazy-loaded relation outside production is an N+1 waiting to happen; fail loudly.
        Model::preventLazyLoading(! app()->isProduction());

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
        // Platform admins (the /admin guard, D85) are never tenant users: no matrix applies.
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if (str_starts_with($ability, 'page.') || str_starts_with($ability, 'action.')) {
                return app(Access::class)->allows($user, $ability);
            }

            return null;
        });
    }
}
