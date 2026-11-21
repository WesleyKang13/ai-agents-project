<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WorkshopController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/workshop', WorkshopController::class)->name('workshop');
