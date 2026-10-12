<?php

use Illuminate\Support\Facades\Route;
use Nuwave\Lighthouse\Http\GraphQLController;

Route::post('graphql', GraphQLController::class)
    ->middleware(['auth:api', 'role:educator', 'throttle:api'])
    ->name('graphql');
