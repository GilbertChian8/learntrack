<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', LoginController::class);

Route::middleware('auth:api')->group(function () {
    Route::post('auth/logout', LogoutController::class);
    Route::get('me', MeController::class);
});
