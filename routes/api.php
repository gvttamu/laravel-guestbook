<?php

use App\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->group(function () {
        Route::apiResource('customers', CustomerController::class)
            ->only(['index', 'show'])
            ->middleware('abilities:'.CustomerController::READ_ABILITY);
    });
