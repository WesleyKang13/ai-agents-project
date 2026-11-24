<?php

use App\Http\Controllers\AgentControlController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentEventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectFolderController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\WorkshopController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::redirect('/workshop', '/projects')->name('workshop');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('throttle:30,1')->name('projects.store');
    Route::get('/projects/folders', ProjectFolderController::class)->middleware('throttle:90,1')->name('projects.folders');
    Route::get('/projects/{project}/workshop', WorkshopController::class)->name('projects.workshop');
    Route::post('/projects/{project}/agents', [AgentController::class, 'store'])->middleware('throttle:30,1')->name('agents.store');
    Route::get('/workshop/agents/{agent}', [AgentController::class, 'show'])->name('agents.state');
    Route::get('/workshop/agents/{agent}/events', AgentEventController::class)->name('agents.events');
    Route::prefix('/workshop/agents/{agent}')->middleware('throttle:90,1')->group(function (): void {
        Route::post('/tasks', [AgentControlController::class, 'task'])->name('agents.tasks');
        Route::post('/steer', [AgentControlController::class, 'steer'])->name('agents.steer');
        Route::post('/stop', [AgentControlController::class, 'stop'])->name('agents.stop');
        Route::post('/decisions', [AgentControlController::class, 'decision'])->name('agents.decisions');
        Route::post('/recover', [AgentControlController::class, 'recover'])->name('agents.recover');
    });
});
