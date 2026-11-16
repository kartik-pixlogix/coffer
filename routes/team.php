<?php

use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// Team (spec §7): the Team page permission; Members see only their own profile.
Route::middleware(['auth', 'verified', 'company', 'page:team'])->group(function () {
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team', [TeamController::class, 'store'])->name('team.store');
    Route::get('team/{member}', [TeamController::class, 'show'])->name('team.show');
    Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
    Route::patch('team/{member}/status', [TeamController::class, 'status'])->name('team.status');
    Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
});
