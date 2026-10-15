<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\MeasurementController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — staff app, customer app and future web clients (SRS §8)
|--------------------------------------------------------------------------
| Base URL: /api/v1   ·   Auth: Bearer token (Sanctum)
| Tenant:   optional X-Tenant header (slug or public id), honoured only for
|           an active membership. Errors use the envelope in bootstrap/app.php.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.active'])->group(function () {
        // Account-level endpoints work without a tenant (e.g. a user choosing a house).
        Route::middleware('api.tenant:optional')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('/auth/password', [AuthController::class, 'password'])->name('auth.password');
            Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        });

        // Everything below runs inside a fashion house.
        Route::middleware('api.tenant')->group(function () {
            Route::get('/tenants/current', [TenantController::class, 'current'])->name('tenants.current');

            // Customers (SRS §8.2)
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('idempotent')->name('customers.store');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::get('/customers/{customer}/timeline', [CustomerController::class, 'timeline'])->name('customers.timeline');
            Route::post('/customers/{customer}/follow-ups', [CustomerController::class, 'storeFollowUp'])->middleware('idempotent')->name('customers.follow-ups.store');

            // Measurements (SRS §8.2)
            Route::get('/measurement-templates', [MeasurementController::class, 'templates'])->name('measurement-templates.index');
            Route::get('/customers/{customer}/measurement-sessions', [MeasurementController::class, 'index'])->name('measurement-sessions.index');
            Route::post('/customers/{customer}/measurement-sessions', [MeasurementController::class, 'store'])->middleware('idempotent')->name('measurement-sessions.store');
            Route::get('/measurement-sessions/{session}', [MeasurementController::class, 'show'])->name('measurement-sessions.show');
            Route::patch('/measurement-sessions/{session}', [MeasurementController::class, 'update'])->name('measurement-sessions.update');
            Route::post('/measurement-sessions/{session}/submit', [MeasurementController::class, 'submit'])->middleware('idempotent')->name('measurement-sessions.submit');
            Route::post('/measurement-sessions/{session}/approve', [MeasurementController::class, 'approve'])->middleware('idempotent')->name('measurement-sessions.approve');
            Route::post('/measurement-sessions/{session}/reject', [MeasurementController::class, 'reject'])->name('measurement-sessions.reject');
            Route::get('/measurement-sessions/{session}/comparison', [MeasurementController::class, 'comparison'])->name('measurement-sessions.comparison');
            Route::post('/customers/{customer}/measurement-requests', [MeasurementController::class, 'storeRequest'])->middleware('idempotent')->name('measurement-requests.store');
            Route::post('/measurement-requests/{measurementRequest}/revoke', [MeasurementController::class, 'revokeRequest'])->name('measurement-requests.revoke');

            // Styles & ready-made catalogue (SRS §8.2)
            Route::get('/styles', [CatalogueController::class, 'styles'])->name('styles.index');
            Route::post('/styles', [CatalogueController::class, 'storeStyle'])->middleware('idempotent')->name('styles.store');
            Route::get('/styles/{style}', [CatalogueController::class, 'style'])->name('styles.show');
            Route::patch('/styles/{style}', [CatalogueController::class, 'updateStyle'])->name('styles.update');
            Route::post('/styles/{style}/versions', [CatalogueController::class, 'newVersion'])->name('styles.versions.store');
            Route::post('/styles/{style}/images', [CatalogueController::class, 'uploadImages'])->name('styles.images.store');
            Route::post('/style-versions/{styleVersion}/publish', [CatalogueController::class, 'publish'])->middleware('idempotent')->name('style-versions.publish');
            Route::get('/products', [CatalogueController::class, 'products'])->name('products.index');
            Route::get('/products/lookup', [CatalogueController::class, 'lookup'])->name('products.lookup');
            Route::get('/products/{product}', [CatalogueController::class, 'product'])->name('products.show');
            Route::get('/catalogue', [CatalogueController::class, 'catalogue'])->name('catalogue');

            // Inventory & procurement (SRS §8.2)
            Route::get('/inventory-items', [InventoryController::class, 'items'])->name('inventory-items.index');
            Route::post('/inventory-items', [InventoryController::class, 'storeItem'])->middleware('idempotent')->name('inventory-items.store');
            Route::get('/inventory-items/{item}', [InventoryController::class, 'item'])->name('inventory-items.show');
            Route::get('/stock-balances', [InventoryController::class, 'balances'])->name('stock-balances.index');
            Route::get('/stock-movements', [InventoryController::class, 'movements'])->name('stock-movements.index');
            Route::post('/stock-transfers', [InventoryController::class, 'transfer'])->middleware('idempotent')->name('stock-transfers.store');
            Route::post('/stock-counts', [InventoryController::class, 'count'])->middleware('idempotent')->name('stock-counts.store');
            Route::post('/material-issues', [InventoryController::class, 'issue'])->middleware('idempotent')->name('material-issues.store');
            Route::get('/suppliers', [InventoryController::class, 'suppliers'])->name('suppliers.index');
            Route::get('/purchase-orders', [InventoryController::class, 'purchaseOrders'])->name('purchase-orders.index');
            Route::post('/purchase-orders', [InventoryController::class, 'storePurchaseOrder'])->middleware('idempotent')->name('purchase-orders.store');
            Route::get('/purchase-orders/{purchaseOrder}', [InventoryController::class, 'purchaseOrder'])->name('purchase-orders.show');
            Route::post('/purchase-orders/{purchaseOrder}/submit', [InventoryController::class, 'submitPurchaseOrder'])->name('purchase-orders.submit');
            Route::post('/purchase-orders/{purchaseOrder}/approve', [InventoryController::class, 'approvePurchaseOrder'])->middleware('idempotent')->name('purchase-orders.approve');
            Route::post('/goods-receipts', [InventoryController::class, 'receive'])->middleware('idempotent')->name('goods-receipts.store');

            /*
            | Further module endpoints (orders, production, …) go here.
            */
        });
    });
});
