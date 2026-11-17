<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use App\Support\Permissions\Catalog;
use App\Support\Permissions\PageRoutes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The designed error pages (State404.dc.html, and the 403 / 419 / 500 / 503 pages
 * derived from it) rendered through Inertia as `errors/error` (D89).
 *
 * Only outside debug mode: locally the framework's debug pages stay. JSON requests,
 * the Inertia "session expired" answer (D30) and any status not listed keep the
 * framework's response, and so does anything that fails while rendering the page.
 */
final class ErrorPage
{
    public const array STATUSES = [403, 404, 419, 500, 503];

    /** "Or jump to" on the 404, in the board's order, kept to what the role may open. */
    private const array PLACES = ['expenses', 'income', 'timesheets', 'settlements', 'vault', 'settings'];

    public static function applies(Request $request, Response $response): bool
    {
        return ! app()->hasDebugModeEnabled()
            && ! $request->expectsJson()
            && ! $response->headers->has('X-Coffer-Session')
            && in_array($response->getStatusCode(), self::STATUSES, true);
    }

    public static function render(Request $request, Response $response): Response
    {
        $status = $response->getStatusCode();

        try {
            return Inertia::render('errors/error', self::props($request, $status))
                ->toResponse($request)
                ->setStatusCode($status);
        } catch (Throwable $e) {
            report($e);

            return $response;
        }
    }

    /** @return array<string, mixed> */
    public static function props(Request $request, int $status): array
    {
        $props = [
            'status' => $status,
            'path' => $request->getHost().'/'.ltrim($request->path(), '/'),
            'landing' => null,
            'reports' => null,
            'places' => [],
        ];

        // In maintenance mode (503) no session was started, so nobody is signed in.
        $user = $request->hasSession() ? $request->user() : null;
        $current = app(CurrentCompany::class);
        $role = $current->role();

        if (! $user instanceof User || $current->get() === null || $role === null) {
            return $props;
        }

        $allowed = app(Access::class)->pages($user);
        $places = [];

        foreach (self::PLACES as $page) {
            $url = ($allowed[$page] ?? false) ? PageRoutes::url($page) : null;

            if ($url !== null) {
                $places[] = ['label' => $page === 'vault' ? 'Credentials Vault' : Catalog::PAGES[$page]['label'], 'url' => $url];
            }
        }

        return [
            ...$props,
            'landing' => DeniedPage::landing($role, $allowed),
            'reports' => ($allowed['reports'] ?? false) ? PageRoutes::url('reports') : null,
            'places' => $places,
        ];
    }
}
