<?php

use App\Http\Middleware\EnsureEducatorOnConsent;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::prefix('oauth')->name('passport.')->group(function () {
    Route::post('token', [AccessTokenController::class, 'issueToken'])->middleware('throttle:oauth-token')->name('token');

    Route::middleware(['web', 'auth', EnsureEducatorOnConsent::class])->group(function () {
        Route::get('authorize', [AuthorizationController::class, 'authorize'])->name('authorizations.authorize');
        Route::post('authorize', [ApproveAuthorizationController::class, 'approve'])->name('authorizations.approve');
        Route::delete('authorize', [DenyAuthorizationController::class, 'deny'])->name('authorizations.deny');
    });
});
