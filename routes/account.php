<?php

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Account › Profile and security (spec §17). Fortify handles 2FA enable, confirm and codes.
Route::middleware(['auth', 'company'])->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/', [ProfileController::class, 'update'])->middleware('throttle:20,1')->name('profile.update');

    Route::post('avatar', [SecurityController::class, 'updateAvatar'])->middleware('throttle:10,1')->name('profile.avatar.update');
    Route::delete('avatar', [SecurityController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

    Route::put('password', [SecurityController::class, 'updatePassword'])->middleware('throttle:6,1')->name('profile.password.update');

    Route::delete('sessions/others', [SecurityController::class, 'destroyOtherSessions'])->name('profile.sessions.destroy-others');
    Route::delete('sessions/{session}', [SecurityController::class, 'destroySession'])->name('profile.sessions.destroy');

    Route::delete('trusted-devices', [SecurityController::class, 'forgetTrustedDevices'])->name('profile.trusted-devices.destroy');
    Route::get('two-factor/start', [SecurityController::class, 'startTwoFactor'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('profile.two-factor.start');
    Route::post('two-factor/replace', [SecurityController::class, 'replaceTwoFactor'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('profile.two-factor.replace');
});
