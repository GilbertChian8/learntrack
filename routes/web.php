<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('login'))->name('home');

require __DIR__.'/auth.php';
