<?php

use App\Http\Controllers\CapitalAccountsController;
use App\Http\Controllers\ExpensesController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\PartnerLoansController;
use App\Http\Controllers\ReimbursementsController;
use App\Http\Controllers\SettlementsController;
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

// Expenses (spec §10): the Expenses page permission (Members see their own); approvals
// need "Approve / reject expenses", never on your own unless you're an Admin.
Route::middleware(['auth', 'verified', 'company', 'page:expenses'])->prefix('expenses')->group(function () {
    Route::get('/', [ExpensesController::class, 'index'])->name('expenses.index');
    Route::get('export', [ExpensesController::class, 'export'])->middleware('throttle:20,1')->name('expenses.export');
    Route::post('/', [ExpensesController::class, 'store'])->middleware('throttle:120,1')->name('expenses.store');
    Route::post('bulk', [ExpensesController::class, 'bulk'])->middleware('throttle:30,1')->name('expenses.bulk');
    // Personal-card payouts (D50): "Manage capital and loans".
    Route::post('reimbursements', [ReimbursementsController::class, 'store'])->middleware('throttle:60,1')->name('expenses.reimburse');
    Route::delete('reimbursements/{reimbursement}', [ReimbursementsController::class, 'destroy'])->name('expenses.reimbursements.destroy');
    Route::put('{expense}', [ExpensesController::class, 'update'])->name('expenses.update');
    Route::delete('{expense}', [ExpensesController::class, 'destroy'])->name('expenses.destroy');
    Route::post('{expense}/approve', [ExpensesController::class, 'approve'])->name('expenses.approve');
    Route::post('{expense}/reject', [ExpensesController::class, 'reject'])->name('expenses.reject');
    Route::get('{expense}/receipt', [ExpensesController::class, 'receipt'])->name('expenses.receipt');
});

// Capital accounts (spec §11): the page permission; changes need "Manage capital and loans".
Route::middleware(['auth', 'verified', 'company', 'page:capital_accounts'])->prefix('capital')->group(function () {
    Route::get('/', [CapitalAccountsController::class, 'index'])->name('capital-accounts.index');
    Route::get('export', [CapitalAccountsController::class, 'export'])->middleware('throttle:20,1')->name('capital-accounts.export');
    Route::post('/', [CapitalAccountsController::class, 'store'])->middleware('throttle:60,1')->name('capital-accounts.store');
    Route::delete('{movement}', [CapitalAccountsController::class, 'destroy'])->name('capital-accounts.destroy');
});

// Partner loans (spec §12): the page permission; changes need "Manage capital and loans".
Route::middleware(['auth', 'verified', 'company', 'page:partner_loans'])->prefix('loans')->group(function () {
    Route::get('/', [PartnerLoansController::class, 'index'])->name('partner-loans.index');
    Route::post('/', [PartnerLoansController::class, 'store'])->middleware('throttle:60,1')->name('partner-loans.store');
    Route::delete('repayments/{repayment}', [PartnerLoansController::class, 'destroyRepayment'])->name('partner-loans.repayments.destroy');
    Route::post('{loan}/repayments', [PartnerLoansController::class, 'repay'])->middleware('throttle:60,1')->name('partner-loans.repay');
    Route::put('{loan}', [PartnerLoansController::class, 'update'])->middleware('throttle:60,1')->name('partner-loans.update');
    Route::delete('{loan}', [PartnerLoansController::class, 'destroy'])->name('partner-loans.destroy');
});

// Settlements (spec §13, JV only): the page permission; transfers need "Record settlement transfer".
Route::middleware(['auth', 'verified', 'company', 'page:settlements'])->prefix('settlements')->group(function () {
    Route::get('/', [SettlementsController::class, 'index'])->name('settlements.index');
    Route::post('/', [SettlementsController::class, 'store'])->middleware('throttle:60,1')->name('settlements.store');
    Route::put('{transfer}', [SettlementsController::class, 'update'])->name('settlements.update');
    Route::delete('{transfer}', [SettlementsController::class, 'destroy'])->name('settlements.destroy');
});
