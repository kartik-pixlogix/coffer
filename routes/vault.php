<?php

use App\Http\Controllers\Vault\VaultAuditController;
use App\Http\Controllers\Vault\VaultController;
use App\Http\Controllers\Vault\VaultHealthController;
use App\Http\Controllers\Vault\VaultItemController;
use App\Http\Controllers\Vault\VaultOffboardingController;
use App\Http\Controllers\Vault\VaultPoliciesController;
use App\Http\Controllers\Vault\VaultRequestsController;
use App\Http\Controllers\Vault\VaultSecretController;
use App\Http\Controllers\Vault\VaultShareController;
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

    // Sharing (spec §14.2, D72): the share drawer's JSON, behind the lock.
    Route::middleware(['vault.unlocked', 'throttle:vault-share'])->group(function () {
        Route::get('vault/items/{item}/share', [VaultShareController::class, 'showItem'])->name('vault.items.share');
        Route::post('vault/items/{item}/grants', [VaultShareController::class, 'storeItem'])->name('vault.items.grants.store');
        Route::get('vaults/{vault}/share', [VaultShareController::class, 'showVault'])->name('vault.vaults.share');
        Route::post('vaults/{vault}/grants', [VaultShareController::class, 'storeVault'])->name('vault.vaults.grants.store');
        Route::patch('vault/grants/{grant}', [VaultShareController::class, 'update'])->name('vault.grants.update');
        Route::delete('vault/grants/{grant}', [VaultShareController::class, 'destroy'])->name('vault.grants.destroy');
    });

    // Access requests (spec §14.2): ask from the drawer or rail; Managers decide in the inbox.
    Route::get('vault/requests', [VaultRequestsController::class, 'index'])->name('vault.requests');
    Route::post('vault/requests', [VaultRequestsController::class, 'store'])->middleware('throttle:vault-requests')->name('vault.requests.store');
    Route::delete('vault/requests/{accessRequest}', [VaultRequestsController::class, 'destroy'])->name('vault.requests.destroy');
    // Security health (spec §14.4, D74): fix-it lists, the optional breach check, a CSV of the issues.
    Route::get('vault/health', [VaultHealthController::class, 'index'])->name('vault.health');
    Route::middleware('vault.unlocked')->group(function () {
        Route::post('vault/health/check', [VaultHealthController::class, 'check'])->middleware('throttle:vault-requests')->name('vault.health.check');
        Route::get('vault/health/export', [VaultHealthController::class, 'export'])->middleware('throttle:vault-files')->name('vault.health.export');
    });

    // Audit log (spec §14.4, D75): Admins or "Export and view audit log"; the CSV is itself audited.
    Route::get('vault/audit', [VaultAuditController::class, 'index'])->name('vault.audit');
    Route::get('vault/audit/export', [VaultAuditController::class, 'export'])->middleware(['vault.unlocked', 'throttle:vault-files'])->name('vault.audit.export');

    // Policies (spec §14.4, D76): Admins only; saving needs the vault unlocked.
    Route::get('vault/policies', [VaultPoliciesController::class, 'index'])->name('vault.policies');
    Route::put('vault/policies', [VaultPoliciesController::class, 'update'])->middleware(['vault.unlocked', 'throttle:vault-requests'])->name('vault.policies.update');

    // Offboarding rotate lists (D73).
    Route::post('vault/offboardings/{offboarding}/dismiss', [VaultOffboardingController::class, 'dismiss'])->name('vault.offboardings.dismiss');

    Route::middleware('vault.unlocked')->group(function () {
        Route::post('vault/requests/{accessRequest}/approve', [VaultRequestsController::class, 'approve'])->name('vault.requests.approve');
        Route::post('vault/requests/{accessRequest}/deny', [VaultRequestsController::class, 'deny'])->name('vault.requests.deny');
    });
});
