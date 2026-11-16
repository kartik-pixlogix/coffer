<?php

use App\Http\Controllers\ExpensesController;
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

// Expenses (spec §10): the Expenses page permission (Members see their own); approvals
// need "Approve / reject expenses", never on your own unless you're an Admin.
Route::middleware(['auth', 'verified', 'company', 'page:expenses'])->prefix('expenses')->group(function () {
    Route::get('/', [ExpensesController::class, 'index'])->name('expenses.index');
    Route::get('export', [ExpensesController::class, 'export'])->middleware('throttle:20,1')->name('expenses.export');
    Route::post('/', [ExpensesController::class, 'store'])->middleware('throttle:120,1')->name('expenses.store');
    Route::post('bulk', [ExpensesController::class, 'bulk'])->middleware('throttle:30,1')->name('expenses.bulk');
    Route::put('{expense}', [ExpensesController::class, 'update'])->name('expenses.update');
    Route::delete('{expense}', [ExpensesController::class, 'destroy'])->name('expenses.destroy');
    Route::post('{expense}/approve', [ExpensesController::class, 'approve'])->name('expenses.approve');
    Route::post('{expense}/reject', [ExpensesController::class, 'reject'])->name('expenses.reject');
    Route::get('{expense}/receipt', [ExpensesController::class, 'receipt'])->name('expenses.receipt');
});
