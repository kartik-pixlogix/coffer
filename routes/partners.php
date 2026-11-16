<?php

use App\Http\Controllers\PartnersController;
use Illuminate\Support\Facades\Route;

// Partners (spec §6): the Partners page permission (Admins and Partners by default).
Route::middleware(['auth', 'verified', 'company', 'page:partners'])->group(function () {
    Route::get('partners', [PartnersController::class, 'index'])->name('partners.index');
});
