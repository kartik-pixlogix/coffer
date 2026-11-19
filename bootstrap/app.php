<?php

use App\Enums\Theme;
use App\Exceptions\PageDeniedException;
use App\Http\Middleware\BlockVaultWhileImpersonating;
use App\Http\Middleware\EnsureHasCompany;
use App\Http\Middleware\EnsurePageAllowed;
use App\Http\Middleware\EnsureVaultUnlocked;
use App\Http\Middleware\EnsureWorkspaceNotSuspended;
use App\Http\Middleware\EnsureWorkspaceWritable;
use App\Http\Middleware\GuardAccountSecurity;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Responses\DeniedPage;
use App\Http\Responses\ErrorPage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSP, HSTS and the other hardening headers on every response, /admin included (D93).
        $middleware->append(SecurityHeaders::class);

        $middleware->encryptCookies(except: [Theme::COOKIE, 'sidebar_state']);

        // Stripe posts webhooks without a session; Cashier checks their signature instead (D80).
        $middleware->validateCsrfTokens(except: ['stripe/*']);

        $middleware->web(append: [
            HandleAppearance::class,
            SetCurrentCompany::class,
            HandleInertiaRequests::class,
            EnsureWorkspaceNotSuspended::class,
            EnsureWorkspaceWritable::class,
            GuardAccountSecurity::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The current company must be known before route-model binding runs, so bound
        // models go through the BelongsToCompany scope (another company's id → 404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentCompany::class);

        $middleware->alias([
            'company' => EnsureHasCompany::class,
            'page' => EnsurePageAllowed::class,
            'vault.unlocked' => EnsureVaultUnlocked::class,
            'not.impersonating' => BlockVaultWhileImpersonating::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error tracking (D97): exceptions go to the log stack (LOG_STACK). Sentry isn't a
        // dependency; once `sentry/sentry-laravel` is installed and SENTRY_LARAVEL_DSN set,
        // its integration is wired here with no code change (docs/DEPLOY.md).
        $sentry = ['Sentry\Laravel\Integration', 'handles'];

        if (filled(config('services.sentry.dsn')) && is_callable($sentry)) {
            $sentry($exceptions);
        }

        // Vault secrets never go back into the session as "old input" (spec §14.3).
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'secrets', 'custom_fields', 'code', 'passphrase', 'passphrase_confirmation']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // F9 (D115): an unknown /api/* address is a plain JSON 404, never a debug dump.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*') ? response()->json(['message' => 'Not found.'], 404) : null;
        });

        // An Inertia request after the session died (signed out, or the CSRF token is stale):
        // tell the page, which opens the re-auth dialog over itself instead of navigating (D30).
        $exceptions->render(function (AuthenticationException|TokenMismatchException $e, Request $request) {
            if ($request->header('X-Inertia') !== null) {
                return response('Your session ended.', $e instanceof TokenMismatchException ? 419 : 401)
                    ->header('X-Coffer-Session', 'expired');
            }

            return null;
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            // A page or action the role can't use renders "Permission denied" inside the shell.
            if ($response->getStatusCode() === 403 && ! $request->expectsJson() && $request->hasSession() && $request->user() !== null) {
                return DeniedPage::render($request, $e instanceof PageDeniedException ? $e->page : null);
            }

            // 403 / 404 / 419 always, and 500 / 503 outside debug mode, render the designed error page (D89, D115).
            return ErrorPage::applies($request, $response) ? ErrorPage::render($request, $response) : $response;
        });
    })->create();
