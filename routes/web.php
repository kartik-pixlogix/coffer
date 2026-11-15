<?php

use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

// Component gallery for comparing the UI kit with design/design-system previews.
Route::inertia('dev/ui', 'dev/ui')
    ->middleware(EnsureLocalEnvironment::class)
    ->name('dev.ui');

require __DIR__.'/settings.php';
