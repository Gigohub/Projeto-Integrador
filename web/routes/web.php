<?php

use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

// Modo tempo real sem banco de dados (acesso direto ao painel)
Route::get('/', fn () => view('dashboard'))->name('dashboard');
Route::get('/login', fn () => redirect()->route('dashboard'))->name('login');
Route::post('/logout', fn () => redirect()->route('dashboard'))->name('logout');

Route::prefix('panel')->group(function () {
    Route::get('/history', [TelemetryController::class, 'history']);
    Route::post('/send', [TelemetryController::class, 'sendCommand']);
    Route::post('/simulate', [TelemetryController::class, 'simulate']);
    Route::get('/logs', [TelemetryController::class, 'logs']);
});