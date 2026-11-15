<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Theme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ThemeUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

final class ThemeController extends Controller
{
    /**
     * Remember the user's light/dark choice from the top-bar toggle.
     */
    public function update(ThemeUpdateRequest $request): RedirectResponse
    {
        $theme = $request->theme();

        $user = $request->user();
        $user->theme = $theme;
        $user->save();

        Cookie::queue(Cookie::make(Theme::COOKIE, $theme->value, 60 * 24 * 365, httpOnly: false));

        return back();
    }
}
