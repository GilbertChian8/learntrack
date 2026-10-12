<?php

use App\Http\Controllers\Web\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->middleware('auth')->name('home');

require __DIR__.'/auth.php';
