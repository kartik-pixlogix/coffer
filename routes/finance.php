<?php

use App\Http\Controllers\IncomeController;
use Illuminate\Support\Facades\Route;

// Income (spec §9): the Income page permission; changes need "Add / edit income".
Route::middleware(['auth', 'verified', 'company', 'page:income'])->prefix('income')->group(function () {
    Route::get('/', [IncomeController::class, 'index'])->name('income.index');
    Route::get('export', [IncomeController::class, 'export'])->middleware('throttle:20,1')->name('income.export');
    Route::post('/', [IncomeController::class, 'store'])->middleware('throttle:120,1')->name('income.store');
    Route::put('{income}', [IncomeController::class, 'update'])->name('income.update');
    Route::delete('{income}', [IncomeController::class, 'destroy'])->name('income.destroy');
    Route::get('{income}/attachment', [IncomeController::class, 'attachment'])->name('income.attachment');
});
