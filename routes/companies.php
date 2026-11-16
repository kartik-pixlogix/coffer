<?php

use App\Http\Controllers\Companies\CreateCompanyController;
use Illuminate\Support\Facades\Route;

// Create-company wizard (spec §3.1). Reachable with no company yet: new sign-ups land here.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('companies/create', [CreateCompanyController::class, 'create'])->name('companies.create');
});
