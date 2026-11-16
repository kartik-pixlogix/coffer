<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Invites\InviteController;
use Illuminate\Support\Facades\Route;

// "Continue with Google" (spec §18). Fortify registers the email + password routes.
Route::get('auth/google/redirect', [GoogleController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('google.redirect');
Route::get('auth/google/callback', [GoogleController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('google.callback');

// The invite page works signed out (create a password) and signed in (join as you).
Route::get('invites/{token}', [InviteController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('invites.show');
Route::post('invites/{token}/accept', [InviteController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('invites.accept');
Route::post('invites/{token}/decline', [InviteController::class, 'decline'])
    ->middleware('throttle:10,1')
    ->name('invites.decline');
Route::post('invites/{token}/renew', [InviteController::class, 'renew'])
    ->middleware('throttle:5,1')
    ->name('invites.renew');
