<?php

use App\Http\Controllers\ReportsController;
use App\Http\Controllers\StatementsController;
use Illuminate\Support\Facades\Route;

// Reports (spec §15): the Reports page permission; exports are rate-limited (spec §23).
Route::middleware(['auth', 'verified', 'company', 'page:reports'])->prefix('reports')->group(function () {
    Route::get('/', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('export', [ReportsController::class, 'export'])->middleware('throttle:20,1')->name('reports.export');
    Route::get('exports/{export}/download', [ReportsController::class, 'download'])->name('reports.exports.download');
});

// Statements (Statements.dc.html): Trial balance and Balance sheet have their own permissions.
Route::middleware(['auth', 'verified', 'company', 'page:trial_balance'])->prefix('trial-balance')->group(function () {
    Route::get('/', [StatementsController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('export', [StatementsController::class, 'exportTrialBalance'])->middleware('throttle:20,1')->name('reports.trial-balance.export');
});

Route::middleware(['auth', 'verified', 'company', 'page:balance_sheet'])->prefix('balance-sheet')->group(function () {
    Route::get('/', [StatementsController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('export', [StatementsController::class, 'exportBalanceSheet'])->middleware('throttle:20,1')->name('reports.balance-sheet.export');
});
