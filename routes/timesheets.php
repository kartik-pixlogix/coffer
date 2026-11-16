<?php

use App\Http\Controllers\TimesheetImportController;
use App\Http\Controllers\TimesheetsController;
use Illuminate\Support\Facades\Route;

// Timesheets (spec §8): the Timesheets page permission; Members see only their own entries.
Route::middleware(['auth', 'verified', 'company', 'page:timesheets'])->prefix('timesheets')->group(function () {
    Route::get('/', [TimesheetsController::class, 'index'])->name('timesheets.index');
    Route::post('/', [TimesheetsController::class, 'store'])->middleware('throttle:120,1')->name('timesheets.store');
    Route::put('entries/{entry}', [TimesheetsController::class, 'update'])->name('timesheets.update');
    Route::delete('entries/{entry}', [TimesheetsController::class, 'destroy'])->name('timesheets.destroy');

    // Bulk import (spec §8.2).
    Route::get('import', [TimesheetImportController::class, 'index'])->name('timesheets.import');
    Route::get('import/template', [TimesheetImportController::class, 'template'])->name('timesheets.import.template');
    Route::post('import', [TimesheetImportController::class, 'store'])->middleware('throttle:20,1')->name('timesheets.import.store');
    Route::put('import/{import}/mapping', [TimesheetImportController::class, 'mapping'])->name('timesheets.import.mapping');
    Route::post('import/{import}/commit', [TimesheetImportController::class, 'commit'])->middleware('throttle:20,1')->name('timesheets.import.commit');
    Route::get('import/{import}/errors', [TimesheetImportController::class, 'errors'])->name('timesheets.import.errors');
    Route::delete('import/{import}', [TimesheetImportController::class, 'destroy'])->name('timesheets.import.destroy');
    Route::post('monthly', [TimesheetImportController::class, 'monthly'])->name('timesheets.monthly');
});
