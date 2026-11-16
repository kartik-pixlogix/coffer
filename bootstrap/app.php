<?php

use App\Enums\Theme;
use App\Exceptions\PageDeniedException;
use App\Http\Middleware\EnsureHasCompany;
use App\Http\Middleware\EnsurePageAllowed;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentCompany;
use App\Http\Responses\DeniedPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A page or action the role can't use renders "Permission denied" inside the shell.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            if ($response->getStatusCode() !== 403 || $request->expectsJson() || $request->user() === null) {
                return $response;
            }

            return DeniedPage::render($request, $e instanceof PageDeniedException ? $e->page : null);
        });
    })->create();
