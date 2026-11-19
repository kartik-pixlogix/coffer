<?php

use App\Http\Controllers\Companies\CreateCompanyController;
use Illuminate\Support\Facades\Route;

// Create-company wizard (spec §3.1). Reachable with no company yet: new sign-ups land here.
Route::middleware(['auth', 'verified', 'can:company.create'])->group(function () {
    Route::get('companies/create', [CreateCompanyController::class, 'create'])->name('companies.create');
    Route::post('companies/create/check', [CreateCompanyController::class, 'check'])
        ->middleware('throttle:60,1')
        ->name('companies.check');
    Route::put('companies/draft', [CreateCompanyController::class, 'saveDraft'])
        ->middleware('throttle:30,1')
        ->name('companies.draft');
    Route::post('companies', [CreateCompanyController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('companies.store');
});
