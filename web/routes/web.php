<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

// Rotas públicas de autenticação (visitantes)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');
});

// Rotas protegidas (exigem login: admin ou user)
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', fn () => view('dashboard'))->name('dashboard');

    Route::prefix('panel')->group(function () {
        Route::get('/history', [TelemetryController::class, 'history']);
        Route::get('/export-csv', [TelemetryController::class, 'exportCsv']);
        Route::post('/send', [TelemetryController::class, 'sendCommand']);

        // Auditoria criptografada restrita ao perfil administrador (HTTP 403 para 'user')
        Route::get('/logs', [TelemetryController::class, 'logs'])->middleware('admin');
    });
});