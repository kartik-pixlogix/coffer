<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Where sign-in sends someone (G10, G13, D113): back to the page they asked for, but never
 * to a file, export or JSON endpoint (a CSV download as the first thing after sign-in),
 * and otherwise `/home`, which lands each role on its first permitted page.
 */
final class IntendedUrl
{
    /** Route names that are files, exports or JSON, never a page to land on. */
    private const array NOT_PAGES = [
        '*.export', '*.export.*', '*.exports.*', '*export*', '*download*', '*.template', '*.errors',
        'vault.attachments.*', '*.receipt', '*.receipts.*', 'two-factor.*', 'notifications.panel',
        'search', 'search.*', 'logout', 'password.confirmation', 'vault.items.share', 'vault.vaults.share',
    ];

    /** Pull the intended URL from the session and keep it only if it's a page. */
    public static function pull(Request $request): string
    {
        $intended = $request->hasSession() ? $request->session()->pull('url.intended') : null;

        return is_string($intended) && self::isPage($intended) ? $intended : route('home');
    }

    public static function isPage(string $url): bool
    {
        $root = rtrim(url('/'), '/');

        if ($url !== $root && ! Str::startsWith($url, $root.'/') && ! (Str::startsWith($url, '/') && ! Str::startsWith($url, '//'))) {
            return false;
        }

        try {
            $route = Route::getRoutes()->match(Request::create($url, 'GET'));
        } catch (HttpException) {
            return false;
        }

        $name = $route->getName();
        $uri = $route->uri();

        if ($name === null || Str::startsWith($uri, ['api/', 'user/', 'admin', 'stripe/', 'horizon'])) {
            return false;
        }

        return ! Str::is(self::NOT_PAGES, $name);
    }
}
