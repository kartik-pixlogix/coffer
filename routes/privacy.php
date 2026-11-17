<?php

use App\Http\Controllers\Account\PrivacyController;
use Illuminate\Support\Facades\Route;

// Account › Your data (spec §23 DPDPA, D98): exports and deletion, the person's own.
Route::middleware(['auth', 'company'])->prefix('account/data')->name('privacy.')->group(function () {
    Route::post('exports', [PrivacyController::class, 'export'])->middleware('throttle:5,60')->name('exports.store');
    Route::get('exports/{export}/download', [PrivacyController::class, 'download'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('exports.download');

    Route::post('account/delete', [PrivacyController::class, 'deleteAccount'])->middleware('throttle:6,1')->name('account.destroy');
    Route::post('account/restore', [PrivacyController::class, 'keepAccount'])->middleware('throttle:10,1')->name('account.restore');

    // Owner only: checked in the controller (403 for anyone else).
    Route::post('workspaces/{workspace}/delete', [PrivacyController::class, 'deleteWorkspace'])->middleware('throttle:6,1')->name('workspaces.destroy');
    Route::post('workspaces/{workspace}/restore', [PrivacyController::class, 'keepWorkspace'])->middleware('throttle:10,1')->name('workspaces.restore');
});
