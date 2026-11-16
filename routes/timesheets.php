<?php

use App\Http\Controllers\TimesheetsController;
use Illuminate\Support\Facades\Route;

// Timesheets (spec §8): the Timesheets page permission; Members see only their own entries.
Route::middleware(['auth', 'verified', 'company', 'page:timesheets'])->prefix('timesheets')->group(function () {
    Route::get('/', [TimesheetsController::class, 'index'])->name('timesheets.index');
    Route::post('/', [TimesheetsController::class, 'store'])->middleware('throttle:120,1')->name('timesheets.store');
    Route::put('entries/{entry}', [TimesheetsController::class, 'update'])->name('timesheets.update');
    Route::delete('entries/{entry}', [TimesheetsController::class, 'destroy'])->name('timesheets.destroy');
});
