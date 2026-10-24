<?php

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

Route::get('v1/health', fn () => response()->json(['status' => 'ok']))->name('api.v1.health');

Route::prefix('v1/admin/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::middleware(['auth:sanctum', 'platform.admin'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::apiResource('plans', SubscriptionPlanController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('plans/{plan}/publish', [SubscriptionPlanController::class, 'publish']);
    Route::post('plans/{plan}/archive', [SubscriptionPlanController::class, 'archive']);
});
