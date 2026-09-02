<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AvailableSlotsController;
use App\Http\Controllers\Api\V1\BlockedSlotController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientPackController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\CustomAttributeController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PackSessionController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\RegionController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReceiptController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServicePackController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Models\BlockedSlot;
use App\Models\Booking;
use Illuminate\Support\Facades\Route;

// Webhook WooCommerce — HMAC, sin Sanctum
Route::post('/v1/webhooks/woocommerce', [WebhookController::class, 'handle'])
    ->middleware('throttle:woocommerce');

// Auth
Route::middleware('throttle:api_public')->prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::patch('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
});

// Endpoints publicos — sin token
Route::middleware('throttle:api_public')->prefix('v1')->group(function () {
    Route::get('/config', [ConfigController::class, 'index']);
    Route::get('/available_slots', [AvailableSlotsController::class, 'index']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{id}', [ServiceController::class, 'show']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/{id}', [LocationController::class, 'show']);
    Route::get('/regions', [RegionController::class, 'index']);
    Route::get('/comunas', [RegionController::class, 'indexComunas']);
    Route::get('/regions/{id}/comunas', [RegionController::class, 'showComunas']);
    Route::get('/packs', [ServicePackController::class, 'index']);
    Route::get('/packs/{id}', [ServicePackController::class, 'show']);
});

// Endpoints autenticados — Bearer token + scopes
Route::middleware(['auth:sanctum', 'throttle:api_auth'])->prefix('v1')->group(function () {

    // Bookings - providers see only their location's bookings, admins see all
    Route::get('/bookings', [BookingController::class, 'index'])
        ->middleware('scope:bookings:read')
        ->middleware('ownership');
    Route::get('/bookings/{id}', [BookingController::class, 'show'])
        ->middleware('scope:bookings:read')
        ->middleware('ownership:'.Booking::class);
    Route::post('/bookings', [BookingController::class, 'store'])
        ->middleware('scope:bookings:write');
    Route::patch('/bookings/{id}', [BookingController::class, 'update'])
        ->middleware('scope:bookings:write')
        ->middleware('role:provider,admin')
        ->middleware('ownership:'.Booking::class);
    Route::patch('/bookings/{id}/cancel', [BookingController::class, 'cancel'])
        ->middleware('scope:bookings:write')
        ->middleware('role:admin,agent,provider');

    // Clients
    Route::get('/clients', [ClientController::class, 'index'])
        ->middleware('scope:clients:read');
    Route::get('/clients/{id}', [ClientController::class, 'show'])
        ->middleware('scope:clients:read');
    Route::post('/clients', [ClientController::class, 'store'])
        ->middleware('scope:clients:write');
    Route::patch('/clients/{id}', [ClientController::class, 'update'])
        ->middleware('scope:clients:write');
    Route::patch('/clients/{id}/deactivate', [ClientController::class, 'deactivate'])
        ->middleware('scope:clients:write');
    Route::get('/clients/{id}/attributes', [CustomAttributeController::class, 'clientAttributes'])
        ->middleware('scope:clients:read');
    Route::get('/clients/{id}/packs', [ClientPackController::class, 'clientPacks'])
        ->middleware('scope:clients:read');
    Route::get('/clients/{id}/bookings', [ClientController::class, 'bookings'])
        ->middleware('scope:clients:read');
    Route::get('/clients/{id}/payments', [ClientController::class, 'payments'])
        ->middleware('scope:clients:read');

    // Locations (solo admin puede crear o modificar)
    Route::post('/locations', [LocationController::class, 'store'])
        ->middleware('scope:bookings:write')
        ->middleware('role:admin');
    Route::patch('/locations/{id}', [LocationController::class, 'update'])
        ->middleware('scope:bookings:write')
        ->middleware('role:admin');

    // Providers — solo admin escribe, providers pueden leer
    Route::get('/providers', [ProviderController::class, 'index'])
        ->middleware('scope:providers:read');
    Route::get('/providers/{id}', [ProviderController::class, 'show'])
        ->middleware('scope:providers:read');
    Route::post('/providers', [ProviderController::class, 'store'])
        ->middleware('scope:providers:write')
        ->middleware('role:admin');
    Route::patch('/providers/{id}', [ProviderController::class, 'update'])
        ->middleware('scope:providers:write')
        ->middleware('role:admin');
    Route::patch('/providers/{id}/roles', [ProviderController::class, 'assignRoles'])
        ->middleware('scope:providers:write')
        ->middleware('role:admin');

    // Sales
    Route::get('/sales', [SaleController::class, 'index'])->middleware('scope:sales:read');
    Route::post('/sales', [SaleController::class, 'store'])->middleware(['scope:sales:read', 'role:admin']);
    Route::get('/sales/{id}', [SaleController::class, 'show'])->middleware('scope:sales:read');
    Route::patch('/sales/{id}', [SaleController::class, 'update'])->middleware(['scope:sales:read', 'role:admin']);
    Route::delete('/sales/{id}', [SaleController::class, 'destroy'])->middleware(['scope:sales:read', 'role:admin']);

    Route::get('/sales/{id}/transactions',
        [SaleController::class, 'listTransactions'])->middleware('scope:sales:read');
    Route::post('/sales/{id}/transactions',
        [SaleController::class, 'registerTransaction'])->middleware(['scope:sales:read', 'role:admin']);
    Route::delete('/sales/{id}/transactions/{transactionId}',
        [SaleController::class, 'destroyTransaction'])->middleware(['scope:sales:read', 'role:admin']);

    // Receipts
    Route::get('/sales/{id}/receipt', [SaleReceiptController::class, 'show'])->middleware('scope:sales:read');
    Route::post('/sales/{id}/receipt/send', [SaleReceiptController::class, 'send'])->middleware(['scope:sales:read', 'role:admin']);

    // Custom attributes
    Route::get('/custom_attributes', [CustomAttributeController::class, 'index'])
        ->middleware('scope:clients:read');

    // Pack Sessions
    Route::patch('/pack-sessions/{id}', [PackSessionController::class, 'update'])
        ->middleware(['scope:bookings:write', 'role:admin']);

    // Client Packs
    Route::get('/client-packs', [ClientPackController::class, 'index'])
        ->middleware('scope:clients:read');
    Route::get('/client-packs/{id}', [ClientPackController::class, 'show'])
        ->middleware('scope:clients:read');
    Route::post('/client-packs', [ClientPackController::class, 'store'])
        ->middleware('scope:clients:write');
    Route::patch('/client-packs/{id}/use', [ClientPackController::class, 'use'])
        ->middleware('scope:bookings:write');

    // Blocked slots
    Route::get('/blocked-slots', [BlockedSlotController::class, 'index'])
        ->middleware('scope:bookings:read');
    Route::post('/blocked-slots', [BlockedSlotController::class, 'store'])
        ->middleware('scope:bookings:write');
    Route::patch('/blocked-slots/{id}', [BlockedSlotController::class, 'update'])
        ->middleware('scope:bookings:write')
        ->middleware('ownership:'.BlockedSlot::class);
    Route::delete('/blocked-slots/{id}', [BlockedSlotController::class, 'destroy'])
        ->middleware('scope:bookings:write')
        ->middleware('ownership:'.BlockedSlot::class);
    Route::delete('/blocked-slots/group/{repeatGroupId}', [BlockedSlotController::class, 'destroyGroup'])
        ->middleware('scope:bookings:write')
        ->middleware('ownership:'.BlockedSlot::class.',repeatGroupId');

    // Business onboarding — no scope: any authenticated user can read/create
    // their own business profile (R9.1/R9.2)
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);

    // Roles catalog — global business-role definitions, admin only, no
    // tenant required (R11.1); the assignment endpoint enforces onboarding
    Route::get('/roles', [RoleController::class, 'index'])->middleware('role:admin');

    // Tenant settings — admin only, resolved from the authenticated user's tenant
    Route::get('/tenant/settings', [TenantController::class, 'show'])->middleware('role:admin');
    Route::patch('/tenant/settings', [TenantController::class, 'update'])->middleware('role:admin');
    Route::post('/tenant/settings/logo', [TenantController::class, 'uploadLogo'])->middleware('role:admin');

    // Me — authenticated user's own profile (R10.1); no scope. The
    // client-scoped /me below is a different, untouched endpoint.
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateProfile']);

    // Me
    Route::get('/me', [ClientController::class, 'me'])->middleware('scope:clients:read');

    // Notifications — carlitox polling contract
    Route::get('/notifications/pending', [NotificationController::class, 'pending'])
        ->middleware('scope:notifications:read');
    Route::post('/notifications/reminders/ack', [NotificationController::class, 'ack'])
        ->middleware('scope:notifications:write');

    // Agente conversacional
    Route::get('/agent/check-availability', [AgentController::class, 'checkAvailability'])
        ->middleware('scope:bookings:read');
});
