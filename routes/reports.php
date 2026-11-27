<?php

use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\ChartOfAccountsController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\ProfitAndLossController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\StatementsController;
use App\Http\Controllers\TaxReturnsController;
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

// The audit trail (D153): its own permission; "Verify now" and exports are rate-limited.
Route::middleware(['auth', 'verified', 'company', 'page:audit_trail'])->prefix('audit-trail')->group(function () {
    Route::get('/', [AuditTrailController::class, 'index'])->name('audit.index');
    Route::post('verify', [AuditTrailController::class, 'verify'])->middleware('throttle:6,1')->name('audit.verify');
    Route::get('export', [AuditTrailController::class, 'export'])->middleware('throttle:20,1')->name('audit.export');
});

// The Journal / day book (D154): every voucher with its lines.
Route::middleware(['auth', 'verified', 'company', 'page:journal'])->prefix('journal')->group(function () {
    Route::get('/', [JournalController::class, 'index'])->name('reports.journal');
    Route::get('export', [JournalController::class, 'export'])->middleware('throttle:20,1')->name('reports.journal.export');
});

// Statement of profit and loss, Schedule III (D156): the Reports permission.
Route::middleware(['auth', 'verified', 'company', 'page:reports'])->prefix('profit-and-loss')->group(function () {
    Route::get('/', [ProfitAndLossController::class, 'index'])->name('reports.profit-and-loss');
    Route::get('export', [ProfitAndLossController::class, 'export'])->middleware('throttle:20,1')->name('reports.profit-and-loss.export');
});

// Cash flow statement (D157).
Route::middleware(['auth', 'verified', 'company', 'page:cash_flow'])->prefix('cash-flow')->group(function () {
    Route::get('/', [CashFlowController::class, 'index'])->name('reports.cash-flow');
    Route::get('export', [CashFlowController::class, 'export'])->middleware('throttle:20,1')->name('reports.cash-flow.export');
});

// Chart of accounts (D158); changing a category's Schedule III line also needs Settings.
Route::middleware(['auth', 'verified', 'company', 'page:chart_of_accounts'])->prefix('chart-of-accounts')->group(function () {
    Route::get('/', [ChartOfAccountsController::class, 'index'])->name('reports.chart-of-accounts');
    Route::patch('categories/{category}/schedule-line', [ChartOfAccountsController::class, 'updateLine'])->name('reports.chart-of-accounts.schedule-line');
});

// GST and TDS (D163, D164): the Tax page; payments need "Manage tax".
Route::middleware(['auth', 'verified', 'company', 'page:tax'])->prefix('tax')->group(function () {
    Route::get('/', [TaxReturnsController::class, 'index'])->name('tax.index');
    Route::get('export', [TaxReturnsController::class, 'export'])->middleware('throttle:20,1')->name('tax.export');
    Route::post('gst-payments', [TaxReturnsController::class, 'storeGst'])->middleware('throttle:30,1')->name('tax.gst-payments.store');
    Route::delete('gst-payments/{gstPayment}', [TaxReturnsController::class, 'destroyGst'])->name('tax.gst-payments.destroy');
    Route::post('tds-challans', [TaxReturnsController::class, 'storeTds'])->middleware('throttle:30,1')->name('tax.tds-challans.store');
    Route::delete('tds-challans/{tdsChallan}', [TaxReturnsController::class, 'destroyTds'])->name('tax.tds-challans.destroy');
});
