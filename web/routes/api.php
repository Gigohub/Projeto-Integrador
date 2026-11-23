<?php

use App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::middleware('device.token')->group(function () {
    Route::post('/readings', [TelemetryController::class, 'storeReading']);
    Route::get('/commands', [TelemetryController::class, 'pendingCommands']);
    Route::post('/commands/ack', [TelemetryController::class, 'ackCommands']);
});