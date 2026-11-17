<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\PlatformStats;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The super-admin panel at /admin (spec §22, D85). Its own `platform` guard and
 * `platform_admins` table, its own middleware stack (none of the tenant `web` group: no
 * current company, no Inertia, no tenant scopes resolved) and Filament's own published
 * CSS/JS, never the Vite bundle, so its styling can't reach the Inertia app.
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('platform')
            ->login()
            // D94: an authenticator app (with recovery codes) is the second factor; every
            // platform admin sets one up before the panel opens. Profile manages it.
            ->multiFactorAuthentication(
                [AppAuthentication::make()->brandName('Coffer Platform')->recoverable()],
                isRequired: fn (): bool => (bool) config('platform.mfa_required'),
            )
            ->profile(isSimple: false)
            ->brandName('Coffer Platform')
            ->brandLogo(asset('brand/coffer-lockup-on-light.svg'))
            ->darkModeBrandLogo(asset('brand/coffer-lockup-on-dark.svg'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.svg'))
            // Brand colours (spec §22): lime accent, graphite neutrals.
            ->colors([
                'primary' => Color::Lime,
                'gray' => Color::Zinc,
                'danger' => Color::Red,
                'warning' => Color::Amber,
                'success' => Color::Green,
                'info' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                PlatformStats::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
