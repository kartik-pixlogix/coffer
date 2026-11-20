<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Theme;
use App\Models\User;
use App\Support\Accent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class HandleAppearance
{
    /**
     * Share the theme with the root view so `data-theme` is right on first paint.
     * The user's saved theme wins, then the theme cookie; null means "follow the OS",
     * which the inline head script resolves before the page draws.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $cookie = $request->cookie(Theme::COOKIE);

        $theme = $user instanceof User && $user->theme !== null
            ? $user->theme
            : (is_string($cookie) ? Theme::tryFrom($cookie) : null);

        View::share('theme', $theme?->value);
        // The brand gradient (D147): its token overrides go in the head, so the first paint has them.
        View::share('accentCss', $user instanceof User ? Accent::fromStored($user->accent)->css() : null);

        return $next($request);
    }
}
