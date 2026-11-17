<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (spec §23, D93).
 *
 * - The Inertia app gets a strict CSP: scripts only from this origin or with the
 *   per-request nonce (Vite tags, the React refresh preamble and the theme script in
 *   app.blade.php carry it), no plugins, no framing, forms only to this origin. Styles
 *   allow inline because React renders `style` attributes (and SSR on the marketing pages).
 * - Filament's /admin (and Livewire's endpoints) get a relaxed script policy, because
 *   Alpine evaluates expressions with `new Function` and Livewire injects inline scripts.
 * - In local, the Vite dev server's origin (public/hot) is allowed for scripts, styles,
 *   fonts, images and its websocket, so `composer run dev` keeps hot reloading.
 * - HSTS only in production (behind HTTPS).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (app()->isProduction()) {
            $hsts = 'max-age='.config('security.hsts_max_age').'; includeSubDomains';
            $headers->set('Strict-Transport-Security', config('security.hsts_preload') ? $hsts.'; preload' : $hsts);
        }

        // The framework's debug error page (local only) uses inline scripts of its own.
        $debugError = config('app.debug') && $response->getStatusCode() >= 500;

        if (config('security.csp_enabled') && ! $debugError) {
            $name = config('security.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $headers->set($name, $this->policy($request, $nonce));
        }

        return $response;
    }

    public function policy(Request $request, string $nonce): string
    {
        $dev = $this->devServer();
        $admin = $request->is('admin', 'admin/*', 'livewire*', 'filament/*');

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action' => ["'self'"],
            'script-src' => $admin
                ? ["'self'", "'unsafe-inline'", "'unsafe-eval'"]
                : ["'self'", "'nonce-{$nonce}'", ...$dev['http']],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev['http']],
            'img-src' => ["'self'", 'data:', 'blob:', ...$dev['http']],
            'font-src' => ["'self'", 'data:', ...$dev['http']],
            'connect-src' => ["'self'", ...$dev['http'], ...$dev['ws']],
            'frame-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ];

        if (app()->isProduction()) {
            $directives['upgrade-insecure-requests'] = [];
        }

        if (filled(config('security.csp_report_uri'))) {
            $directives['report-uri'] = [(string) config('security.csp_report_uri')];
        }

        return collect($directives)
            ->map(fn (array $sources, string $name): string => trim($name.' '.implode(' ', array_unique($sources))))
            ->implode('; ');
    }

    /**
     * The Vite dev server's origin while `npm run dev` runs, local only.
     *
     * @return array{http: list<string>, ws: list<string>}
     */
    private function devServer(): array
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return ['http' => [], 'ws' => []];
        }

        $url = trim((string) @file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return ['http' => [], 'ws' => []];
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $ws = $parts['scheme'] === 'https' ? 'wss' : 'ws';

        return ['http' => ["{$parts['scheme']}://{$host}"], 'ws' => ["{$ws}://{$host}"]];
    }
}
