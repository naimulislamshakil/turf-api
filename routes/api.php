<?php

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BusinessController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Admin\BusinessSubscriptionController;
use App\Http\Controllers\Api\V1\Owner\AuthController as OwnerAuthController;
use Illuminate\Support\Facades\Route;

Route::get('v1/health', fn () => response()->json(['status' => 'ok']))->name('api.v1.health');

Route::prefix('v1/owner/auth')->group(function () {
    Route::post('login', [OwnerAuthController::class, 'login'])->middleware('throttle:owner-login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [OwnerAuthController::class, 'me']);
        Route::post('password', [OwnerAuthController::class, 'changePassword']);
        Route::post('logout', [OwnerAuthController::class, 'logout']);
    });
    Route::middleware(['auth:sanctum', 'owner.password.changed'])->get('profile', [OwnerAuthController::class, 'me']);
});

Route::prefix('v1/admin/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:admin-login');
    Route::middleware(['auth:sanctum', 'platform.admin'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1/admin')->middleware(['auth:sanctum', 'platform.admin'])->group(function () {
    Route::get('subscription-plans/published', [BusinessSubscriptionController::class, 'plans']);
    Route::get('subscription-payments/pending', [BusinessSubscriptionController::class, 'pendingPayments']);
    Route::post('subscription-payments/{payment}/verify', [BusinessSubscriptionController::class, 'verify']);
    Route::post('subscription-payments/{payment}/reject', [BusinessSubscriptionController::class, 'reject']);
    Route::get('businesses/owners', [BusinessController::class, 'owners']);
    Route::post('credential-deliveries/{delivery}/retry', [BusinessController::class, 'retryCredentialDelivery']);
    Route::post('businesses/{business}/credential-reissue', [BusinessController::class, 'reissueCredential']);
    Route::apiResource('businesses', BusinessController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('businesses/{business}/subscription', [BusinessSubscriptionController::class, 'show']);
    Route::post('businesses/{business}/subscription/payments', [BusinessSubscriptionController::class, 'storePayment']);
    Route::post('businesses/{business}/subscription/complete-payment', [BusinessSubscriptionController::class, 'completePayment']);
    Route::apiResource('plans', SubscriptionPlanController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('plans/{plan}/publish', [SubscriptionPlanController::class, 'publish']);
    Route::post('plans/{plan}/archive', [SubscriptionPlanController::class, 'archive']);
});
