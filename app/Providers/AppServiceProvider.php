<?php

namespace App\Providers;

use App\Enums\CompanyRole;
use App\Http\Controllers\Auth\SignOutController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Jobs\CheckVaultBreaches;
use App\Jobs\CommitTimesheetImport;
use App\Jobs\CommitVaultImport;
use App\Jobs\GenerateDataExport;
use App\Jobs\GenerateReportExport;
use App\Jobs\ReencryptVaultData;
use App\Mail\ContactRequestReceived;
use App\Models\Company;
use App\Models\Membership;
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
use App\Services\Vault\MasterKeys\EnvMasterKey;
use App\Services\Vault\MasterKeys\MasterKey;
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
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use RuntimeException;

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

        // Where the master key lives (D152). KMS drivers (aws-kms, gcp-kms) go here once chosen.
        $this->app->bind(MasterKey::class, fn (): MasterKey => match (config('vault.master_key_driver')) {
            'env', null, '' => new EnvMasterKey,
            default => throw new RuntimeException('VAULT_MASTER_KEY_DRIVER '.config('vault.master_key_driver').' is not available. Use env (KMS drivers need their SDK, D152).'),
        });

        // Unwrapped vault keys and access caches live for one request or job.
        $this->app->scoped(VaultKeys::class);
        $this->app->scoped(VaultAccess::class);
        $this->app->scoped(VaultSharing::class);

        // The dashboard's "Credentials expiring soon" (D64).
        $this->app->bind(DashboardVaultPanel::class, VaultExpiringPanel::class);

        // Notifications carry their company in a column (D78).
        $this->app->bind(DatabaseChannel::class, CompanyDatabaseChannel::class);

        // S3 (D111): signing out of the app keeps a platform admin's /admin session.
        $this->app->bind(AuthenticatedSessionController::class, SignOutController::class);

        // Billing (D80): the workspace is Cashier's customer; Stripe is the only gateway so far.
        Cashier::useCustomerModel(Workspace::class);
        $this->app->singleton(WorkspaceBilling::class);
        $this->app->bind(PaymentGateway::class, StripeGateway::class);
        // B4 (D117): no STRIPE_WEBHOOK_SECRET, no webhooks (Cashier alone would accept unsigned ones).
        $this->app->bind(WebhookController::class, StripeWebhookController::class);
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

            // S4 (D111): the platform admin signing out of /admin ends the impersonation too.
            if ($event->guard === 'platform' && session()->has(Impersonator::SESSION)) {
                app(Impersonator::class)->stop();
            }
        });

        Inertia::disableSsr(fn (Request $request): bool => ! $request->routeIs('welcome', 'marketing.*'));

        // The QA sweep's server (docs/QA.md) uses the built assets even while Vite dev runs.
        $hot = config('app.vite_hot_file');

        if (is_string($hot) && $hot !== '') {
            Vite::useHotFile($hot);
        }
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
            GenerateDataExport::class => 'exports',
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

        // The marketing Contact form's hourly limit (D84) lives in ContactController now, so
        // only accepted messages count (W2, D119).
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

        // The create-company wizard (D106): a new sign-up with no company yet, or the Admin
        // (or workspace owner) of the current company. Members, Partners and Viewers can't.
        Gate::define('company.create', function (User $user): bool {
            $current = app(CurrentCompany::class);
            $company = $current->get();

            if ($company === null) {
                return ! $user->companies()->exists();
            }

            return $current->isAdmin() || $company->workspace->isOwnedBy($user);
        });

        // Archive, restore, delete or keep one company (V4, D139–D141): its Admin or the
        // workspace owner. Works for a soft-deleted company too (the membership row stays).
        Gate::define('company.remove', function (User $user, Company $company): bool {
            if ((int) $company->workspace()->value('owner_id') === $user->id) {
                return true;
            }

            return Membership::query()->where('company_id', $company->id)->where('user_id', $user->id)->first()?->role === CompanyRole::Admin;
        });
    }
}
