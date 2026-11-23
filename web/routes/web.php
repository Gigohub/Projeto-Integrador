<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');
});

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('dashboard'))->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Endpoints consumidos pela interface HTML (Alpine.js)
    Route::prefix('panel')->group(function () {
        Route::get('/history', [TelemetryController::class, 'history']);
        Route::post('/send', [TelemetryController::class, 'sendCommand']);
        Route::post('/simulate', [TelemetryController::class, 'simulate']);

        // Exclusivo Admin (Logs de auditoria)
        Route::get('/logs', [TelemetryController::class, 'logs'])->middleware('admin');
    });
});