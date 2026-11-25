<?php

use App\Http\Controllers\TelemetryController;
use App\Http\Middleware\VerifyDeviceToken;
use Illuminate\Support\Facades\Route;

Route::middleware(VerifyDeviceToken::class)->group(function () {
    Route::post('/sync', [TelemetryController::class, 'sync']);
});