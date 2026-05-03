<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailableSlotsController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\CustomAttributeController;
use App\Http\Controllers\Api\V1\WebhookController;

// Webhook WooCommerce — autenticación HMAC, sin Sanctum
Route::post('/v1/webhooks/woocommerce', [WebhookController::class, 'handle'])
    ->middleware('throttle:woocommerce');

// Auth
Route::middleware('throttle:api_public')->prefix('v1')->group(function () {
    Route::post('/auth/login',  [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Endpoints públicos — sin token, 60 req/min
Route::middleware('throttle:api_public')->prefix('v1')->group(function () {
    Route::get('/available_slots', [AvailableSlotsController::class, 'index']);
    Route::get('/services',        [ServiceController::class, 'index']);
    Route::get('/services/{id}',   [ServiceController::class, 'show']);
    Route::get('/locations',       [LocationController::class, 'index']);
    Route::get('/locations/{id}',  [LocationController::class, 'show']);
});

// Endpoints autenticados — Bearer token Sanctum, 300 req/min
Route::middleware(['auth:sanctum', 'throttle:api_auth'])->prefix('v1')->group(function () {

    // Bookings
    Route::get('/bookings',              [BookingController::class, 'index']);
    Route::get('/bookings/{id}',         [BookingController::class, 'show']);
    Route::post('/bookings',             [BookingController::class, 'store']);
    Route::patch('/bookings/{id}',       [BookingController::class, 'update']);
    Route::patch('/bookings/{id}/cancel',[BookingController::class, 'cancel']);

    // Clients
    Route::get('/clients',                          [ClientController::class, 'index']);
    Route::get('/clients/{id}',                     [ClientController::class, 'show']);
    Route::post('/clients',                         [ClientController::class, 'store']);
    Route::patch('/clients/{id}',                   [ClientController::class, 'update']);
    Route::patch('/clients/{id}/deactivate',        [ClientController::class, 'deactivate']);
    Route::get('/clients/{id}/attributes',          [CustomAttributeController::class, 'clientAttributes']);

    // Providers
    Route::get('/providers',     [ProviderController::class, 'index']);
    Route::get('/providers/{id}',[ProviderController::class, 'show']);

    // Sales
    Route::get('/sales',     [SaleController::class, 'index']);
    Route::get('/sales/{id}',[SaleController::class, 'show']);

    // Custom attributes
    Route::get('/custom_attributes', [CustomAttributeController::class, 'index']);
});