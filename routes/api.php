<?php

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerScoringController;
use App\Http\Controllers\Api\V1\CustomerVerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'throttle:60,1'])
    ->group(function () {
        Route::apiResource('customers', CustomerController::class)
            ->only(['index', 'show'])
            ->middleware('abilities:'.CustomerController::READ_ABILITY);

        Route::put('customers/{customer}/verification', [CustomerVerificationController::class, 'update'])
            ->middleware('abilities:'.CustomerVerificationController::VERIFY_ABILITY)
            ->name('customers.verification.update');

        Route::put('customers/{customer}/scoring', [CustomerScoringController::class, 'update'])
            ->middleware('abilities:'.CustomerVerificationController::VERIFY_ABILITY)
            ->name('customers.scoring.update');
    });
