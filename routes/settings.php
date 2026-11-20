<?php

use App\Http\Controllers\Settings\AccentController;
use App\Http\Controllers\Settings\CategoriesController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\CompanyLifecycleController;
use App\Http\Controllers\Settings\InvitesController;
use App\Http\Controllers\Settings\MembersController;
use App\Http\Controllers\Settings\PartnersController;
use App\Http\Controllers\Settings\ProjectsController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\SidesController;
use App\Http\Controllers\Settings\ThemeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::patch('settings/theme', [ThemeController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('theme.update');
    Route::patch('settings/accent', [AccentController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('accent.update');
});

// Archived and deleted companies (V4, D139–D141): restored or kept by their Admin or the
// workspace owner (the `company.remove` gate), from whichever company is open.
Route::middleware(['auth', 'verified'])->prefix('companies/{company}')->group(function () {
    Route::post('restore', [CompanyLifecycleController::class, 'restore'])->middleware('throttle:10,1')->name('companies.restore');
    Route::post('keep', [CompanyLifecycleController::class, 'keep'])->middleware('throttle:10,1')->withTrashed()->name('companies.keep');
});

// Company settings (spec §16): the Settings page permission, Admins by default.
Route::middleware(['auth', 'verified', 'company', 'page:settings'])->prefix('settings')->group(function () {
    Route::redirect('/', '/settings/company');

    Route::get('company', [CompanyController::class, 'index'])->name('settings.company');
    Route::put('company', [CompanyController::class, 'update'])->name('settings.company.update');
    Route::get('company/logo', [CompanyController::class, 'logo'])->name('settings.company.logo');
    Route::post('company/logo', [CompanyController::class, 'uploadLogo'])->middleware('throttle:20,1')->name('settings.company.logo.store');
    Route::delete('company/logo', [CompanyController::class, 'removeLogo'])->name('settings.company.logo.destroy');
    Route::post('company/fiscal-years/{fiscalYear}/close', [CompanyController::class, 'close'])->middleware('throttle:10,1')->name('settings.company.close');
    Route::post('company/fiscal-years/{fiscalYear}/reopen', [CompanyController::class, 'reopen'])->middleware('throttle:10,1')->name('settings.company.reopen');
    Route::post('company/archive', [CompanyLifecycleController::class, 'archive'])->middleware('throttle:6,1')->name('settings.company.archive');
    Route::post('company/delete', [CompanyLifecycleController::class, 'destroy'])->middleware('throttle:6,1')->name('settings.company.delete');

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

    Route::get('projects', [ProjectsController::class, 'index'])->name('settings.projects');
    Route::post('projects', [ProjectsController::class, 'store'])->name('settings.projects.store');
    Route::put('projects/{project}', [ProjectsController::class, 'update'])->name('settings.projects.update');
    Route::patch('projects/{project}/status', [ProjectsController::class, 'status'])->name('settings.projects.status');
    Route::delete('projects/{project}', [ProjectsController::class, 'destroy'])->name('settings.projects.destroy');

    Route::get('categories', [CategoriesController::class, 'index'])->name('settings.categories');
    Route::post('categories', [CategoriesController::class, 'store'])->name('settings.categories.store');
    Route::put('categories/{category}', [CategoriesController::class, 'update'])->name('settings.categories.update');
    Route::patch('categories/{category}/archive', [CategoriesController::class, 'archive'])->name('settings.categories.archive');
});
