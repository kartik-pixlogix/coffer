<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\BrokenLinkController;
use App\Http\Controllers\Companies\SwitchCompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\Platform\StopImpersonationController;
use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;

// A platform admin ends an impersonation from the app's banner (spec §22, D86).
Route::post('impersonation/stop', StopImpersonationController::class)
    ->middleware('auth')
    ->name('impersonation.stop');

Route::middleware(['auth', 'verified'])->group(function () {
    // Where sign-in and the company switcher land (spec §2.1).
    Route::get('home', LandingController::class)->name('home');

    Route::post('companies/{company}/switch', SwitchCompanyController::class)
        ->middleware('throttle:30,1')
        ->name('companies.switch');

    Route::middleware('company')->group(function () {
        Route::get('dashboard', DashboardController::class)->middleware('page:dashboard')->name('dashboard');

        Route::post('access-requests', [AccessRequestController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('access-requests.store');
        Route::delete('access-requests/{accessRequest}', [AccessRequestController::class, 'destroy'])
            ->name('access-requests.destroy');
    });
});

// Component gallery for comparing the UI kit with design/design-system previews.
Route::inertia('dev/ui', 'dev/ui')
    ->middleware(EnsureLocalEnvironment::class)
    ->name('dev.ui');

require __DIR__.'/marketing.php';
require __DIR__.'/account.php';
require __DIR__.'/auth.php';
require __DIR__.'/companies.php';
require __DIR__.'/settings.php';
require __DIR__.'/partners.php';
require __DIR__.'/team.php';
require __DIR__.'/timesheets.php';
require __DIR__.'/finance.php';
require __DIR__.'/reports.php';
require __DIR__.'/vault.php';
require __DIR__.'/billing.php';

// "Tell us this link is broken" on the 404 page (D89).
Route::post('broken-links', BrokenLinkController::class)
    ->middleware('throttle:5,1')
    ->name('broken-links.store');

// Unknown URLs run through the web middleware, so the 404 page knows who is signed
// in and renders inside the app shell (D89).
Route::fallback(fn () => abort(404));
