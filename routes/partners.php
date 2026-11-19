<?php

use App\Http\Controllers\PartnersController;
use Illuminate\Support\Facades\Route;

// Partners (spec §6): the Partners page permission (Admins and Partners by default).
Route::middleware(['auth', 'verified', 'company', 'page:partners'])->group(function () {
    Route::get('partners', [PartnersController::class, 'index'])->name('partners.index');
    // P3 (D116): the partner table for the chosen fiscal year as CSV.
    Route::get('partners/export', [PartnersController::class, 'export'])->middleware('throttle:20,1')->name('partners.export');
});
