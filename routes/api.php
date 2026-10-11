<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\GroupLearnerController;
use App\Http\Controllers\Api\LearnerController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', LoginController::class);

Route::middleware('auth:api')->group(function () {
    Route::post('auth/logout', LogoutController::class);
    Route::get('me', MeController::class);
});

Route::middleware(['auth:api', 'role:educator'])->group(function () {
    Route::get('groups', [GroupController::class, 'index']);
    Route::post('groups', [GroupController::class, 'store']);
    Route::get('groups/{group}', [GroupController::class, 'show']);
    Route::post('groups/{group}/learners', [GroupLearnerController::class, 'store']);
    Route::delete('groups/{group}/learners/{user}', [GroupLearnerController::class, 'destroy'])->whereNumber('user');
    Route::get('learners', LearnerController::class);
    Route::get('content', ContentController::class);
});
