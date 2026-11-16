<?php

use App\Http\Controllers\Vault\VaultController;
use App\Http\Controllers\Vault\VaultItemController;
use App\Http\Controllers\Vault\VaultSecretController;
use Illuminate\Support\Facades\Route;

// Credentials Vault (spec §14). The page permission, then per-item VaultItemPolicy checks;
// secret values only through reveal / copy / totp / attachment downloads (throttle:vault-reveal).
Route::middleware(['auth', 'verified', 'company', 'page:vault'])->group(function () {
    Route::get('vault', [VaultController::class, 'index'])->name('vault.index');
    Route::post('vaults', [VaultController::class, 'storeVault'])->middleware('throttle:10,1')->name('vault.vaults.store');

    Route::post('vault/unlock', [VaultController::class, 'unlock'])->middleware('throttle:20,1')->name('vault.unlock');
    Route::post('vault/lock', [VaultController::class, 'lock'])->name('vault.lock');
    Route::post('vault/touch', [VaultController::class, 'touch'])->middleware('throttle:60,1')->name('vault.touch');
    Route::post('vault/reuse', [VaultSecretController::class, 'reuse'])->middleware('throttle:30,1')->name('vault.reuse');

    Route::get('vault/items/create', [VaultItemController::class, 'create'])->name('vault.items.create');
    Route::post('vault/items', [VaultItemController::class, 'store'])->middleware('throttle:30,1')->name('vault.items.store');
    Route::get('vault/items/{item}/edit', [VaultItemController::class, 'edit'])->name('vault.items.edit');
    Route::put('vault/items/{item}', [VaultItemController::class, 'update'])->middleware('throttle:30,1')->name('vault.items.update');
    Route::delete('vault/items/{item}', [VaultItemController::class, 'destroy'])->name('vault.items.destroy');
    Route::post('vault/items/{item}/favourite', [VaultItemController::class, 'favourite'])->middleware('throttle:60,1')->name('vault.items.favourite');
    Route::get('vault/items/{item}/history', [VaultItemController::class, 'history'])->name('vault.items.history');
    Route::post('vault/items/{item}/versions/{version}/restore', [VaultItemController::class, 'restore'])
        ->whereNumber('version')->name('vault.items.restore');

    Route::middleware('throttle:vault-reveal')->group(function () {
        Route::post('vault/items/{item}/reveal', [VaultSecretController::class, 'reveal'])->name('vault.items.reveal');
        Route::post('vault/items/{item}/copy', [VaultSecretController::class, 'copy'])->name('vault.items.copy');
        Route::post('vault/items/{item}/totp', [VaultSecretController::class, 'totp'])->name('vault.items.totp');
        Route::get('vault/attachments/{attachment}', [VaultSecretController::class, 'download'])->name('vault.attachments.show');
    });
});
