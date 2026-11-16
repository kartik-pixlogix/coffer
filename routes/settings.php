<?php

use App\Http\Controllers\Settings\InvitesController;
use App\Http\Controllers\Settings\MembersController;
use App\Http\Controllers\Settings\PartnersController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SidesController;
use App\Http\Controllers\Settings\ThemeController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::patch('settings/theme', [ThemeController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('theme.update');
});

// Company settings (spec §16): the Settings page permission, Admins by default.
Route::middleware(['auth', 'verified', 'company', 'page:settings'])->prefix('settings')->group(function () {
    Route::redirect('/', '/settings/members');

    Route::get('members', [MembersController::class, 'index'])->name('settings.members');
    Route::patch('members/{user}/role', [MembersController::class, 'updateRole'])->name('settings.members.role');
    Route::delete('members/{user}', [MembersController::class, 'destroy'])->name('settings.members.destroy');
    Route::post('members/{user}/reset-two-factor', [MembersController::class, 'resetTwoFactor'])
        ->middleware('throttle:10,1')
        ->name('settings.members.reset-two-factor');

    Route::post('invites', [InvitesController::class, 'store'])->middleware('throttle:20,1')->name('settings.invites.store');
    Route::post('invites/link', [InvitesController::class, 'link'])->middleware('throttle:20,1')->name('settings.invites.link');
    Route::post('invites/{invite}/resend', [InvitesController::class, 'resend'])->middleware('throttle:20,1')->name('settings.invites.resend');
    Route::delete('invites/{invite}', [InvitesController::class, 'destroy'])->name('settings.invites.destroy');

    Route::get('roles', [RolesController::class, 'index'])->name('settings.roles');
    Route::put('roles', [RolesController::class, 'update'])->name('settings.roles.update');
    Route::post('roles/reset', [RolesController::class, 'reset'])->name('settings.roles.reset');

    Route::get('partners', [PartnersController::class, 'index'])->name('settings.partners');
    Route::post('partners', [PartnersController::class, 'store'])->name('settings.partners.store');
    Route::put('partners/{partner}', [PartnersController::class, 'update'])->name('settings.partners.update');
    Route::delete('partners/{partner}', [PartnersController::class, 'destroy'])->name('settings.partners.destroy');

    Route::get('sides', [SidesController::class, 'index'])->name('settings.sides');
    Route::put('sides', [SidesController::class, 'update'])->name('settings.sides.update');
});

Route::middleware(['auth', 'company'])->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'company'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});
