<?php

use App\Enums\Theme;
use App\Exceptions\PageDeniedException;
use App\Http\Middleware\EnsureHasCompany;
use App\Http\Middleware\EnsurePageAllowed;
use App\Http\Middleware\EnsureVaultUnlocked;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Responses\DeniedPage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: [Theme::COOKIE, 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            SetCurrentCompany::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The current company must be known before route-model binding runs, so bound
        // models go through the BelongsToCompany scope (another company's id → 404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentCompany::class);

        $middleware->alias([
            'company' => EnsureHasCompany::class,
            'page' => EnsurePageAllowed::class,
            'vault.unlocked' => EnsureVaultUnlocked::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Vault secrets never go back into the session as "old input" (spec §14.3).
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'secrets', 'custom_fields', 'code', 'passphrase', 'passphrase_confirmation']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // An Inertia request after the session died (signed out, or the CSRF token is stale):
        // tell the page, which opens the re-auth dialog over itself instead of navigating (D30).
        $exceptions->render(function (AuthenticationException|TokenMismatchException $e, Request $request) {
            if ($request->header('X-Inertia') !== null) {
                return response('Your session ended.', $e instanceof TokenMismatchException ? 419 : 401)
                    ->header('X-Coffer-Session', 'expired');
            }

            return null;
        });

        // A page or action the role can't use renders "Permission denied" inside the shell.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            if ($response->getStatusCode() !== 403 || $request->expectsJson() || $request->user() === null) {
                return $response;
            }

            return DeniedPage::render($request, $e instanceof PageDeniedException ? $e->page : null);
        });
    })->create();
