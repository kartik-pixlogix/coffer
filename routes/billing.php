<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\Settings\NotificationsController as NotificationSettingsController;
use Illuminate\Support\Facades\Route;

// Notifications (spec §19, D78): everyone in a company has their own.
Route::middleware(['auth', 'verified', 'company'])->group(function () {
    Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::get('notifications/panel', [NotificationsController::class, 'panel'])->middleware('throttle:120,1')->name('notifications.panel');
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll'])->middleware('throttle:60,1')->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationsController::class, 'read'])->middleware('throttle:240,1')->name('notifications.read');

    // Settings › Notifications is the user's own choice, so it isn't behind the Settings page (D78).
    Route::get('settings/notifications', [NotificationSettingsController::class, 'index'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->middleware('throttle:30,1')->name('settings.notifications.update');

    // The Upgrade modal can open from a limit anywhere, so its plan list is open to every role (D82).
    Route::get('billing/plans', [BillingController::class, 'plans'])->middleware('throttle:60,1')->name('billing.plans');
});

// Billing (spec §20, D80): the workspace owner and Admins (the locked Billing permission, D29).
Route::middleware(['auth', 'verified', 'company', 'page:billing'])->prefix('billing')->group(function () {
    Route::get('/', [BillingController::class, 'index'])->name('billing.index');
    Route::post('checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
    Route::post('sync', [BillingController::class, 'sync'])->middleware('throttle:10,1')->name('billing.sync');
    Route::get('portal', [BillingController::class, 'portal'])->middleware('throttle:10,1')->name('billing.portal');
    Route::put('details', [BillingController::class, 'details'])->middleware('throttle:20,1')->name('billing.details');
    Route::get('invoices/{invoice}', [BillingController::class, 'invoice'])->middleware('throttle:30,1')->name('billing.invoice');
});
