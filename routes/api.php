<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AvailableSlotsController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\CustomAttributeController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\ServicePackController;
use App\Http\Controllers\Api\V1\ClientPackController;

// Webhook WooCommerce — HMAC, sin Sanctum
Route::post('/v1/webhooks/woocommerce', [WebhookController::class, 'handle'])
    ->middleware('throttle:woocommerce');

// Endpoints publicos — sin token
Route::middleware('throttle:api_public')->prefix('v1')->group(function () {
    Route::get('/available_slots',  [AvailableSlotsController::class, 'index']);
    Route::get('/services',         [ServiceController::class, 'index']);
    Route::get('/services/{id}',    [ServiceController::class, 'show']);
    Route::get('/locations',        [LocationController::class, 'index']);
    Route::get('/locations/{id}',   [LocationController::class, 'show']);
    Route::get('/packs',            [ServicePackController::class, 'index']);
    Route::get('/packs/{id}',       [ServicePackController::class, 'show']);
});

// Endpoints autenticados — Bearer token + scopes
Route::middleware(['auth:sanctum', 'throttle:api_auth'])->prefix('v1')->group(function () {

    // Bookings
    Route::get('/bookings',               [BookingController::class, 'index'])
        ->middleware('scope:bookings:read');
    Route::get('/bookings/{id}',          [BookingController::class, 'show'])
        ->middleware('scope:bookings:read');
    Route::post('/bookings',              [BookingController::class, 'store'])
        ->middleware('scope:bookings:write');
    Route::patch('/bookings/{id}',        [BookingController::class, 'update'])
        ->middleware('scope:bookings:write');
    Route::patch('/bookings/{id}/cancel', [BookingController::class, 'cancel'])
        ->middleware('scope:bookings:write');

    // Clients
    Route::get('/clients',                    [ClientController::class, 'index'])
        ->middleware('scope:clients:read');
    Route::get('/clients/{id}',               [ClientController::class, 'show'])
        ->middleware('scope:clients:read');
    Route::post('/clients',                   [ClientController::class, 'store'])
        ->middleware('scope:clients:write');
    Route::patch('/clients/{id}',             [ClientController::class, 'update'])
        ->middleware('scope:clients:write');
    Route::patch('/clients/{id}/deactivate',  [ClientController::class, 'deactivate'])
        ->middleware('scope:clients:write');
    Route::get('/clients/{id}/attributes',    [CustomAttributeController::class, 'clientAttributes'])
        ->middleware('scope:clients:read');
    Route::get('/clients/{id}/packs',         [ClientPackController::class, 'clientPacks'])
        ->middleware('scope:clients:read');

    // Providers
    Route::get('/providers',      [ProviderController::class, 'index'])
        ->middleware('scope:providers:read');
    Route::get('/providers/{id}', [ProviderController::class, 'show'])
        ->middleware('scope:providers:read');

    // Sales
    Route::get('/sales',      [SaleController::class, 'index'])
        ->middleware('scope:sales:read');
    Route::get('/sales/{id}', [SaleController::class, 'show'])
        ->middleware('scope:sales:read');

    // Custom attributes
    Route::get('/custom_attributes', [CustomAttributeController::class, 'index'])
        ->middleware('scope:clients:read');

    // Client Packs
    Route::get('/client-packs',            [ClientPackController::class, 'index'])
        ->middleware('scope:clients:read');
    Route::get('/client-packs/{id}',       [ClientPackController::class, 'show'])
        ->middleware('scope:clients:read');
    Route::post('/client-packs',           [ClientPackController::class, 'store'])
        ->middleware('scope:clients:write');
    Route::patch('/client-packs/{id}/use', [ClientPackController::class, 'use'])
        ->middleware('scope:bookings:write');
});
