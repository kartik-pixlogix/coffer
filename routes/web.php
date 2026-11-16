<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\Companies\SwitchCompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('welcome');

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
