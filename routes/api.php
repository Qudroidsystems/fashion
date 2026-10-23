<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\ProductionController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\MeasurementController;
use App\Http\Controllers\Api\V1\StaffAppController;
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
    Route::post('/auth/google', [AuthController::class, 'google'])
        ->middleware('throttle:10,1')->name('auth.google');

    // Client project tracking for the customer app — no login, published content only (FR-PRJ-006).
    Route::get('/public/project-tracking/{token}', [ProjectController::class, 'tracking'])
        ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('public.project-tracking');

    Route::middleware(['auth:sanctum', 'api.active'])->group(function () {
        // Account-level endpoints work without a tenant (e.g. a user choosing a house).
        Route::middleware('api.tenant:optional')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('/auth/password', [AuthController::class, 'password'])->name('auth.password');
            Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('/auth/onboarding', [AuthController::class, 'onboardingOptions'])->name('auth.onboarding.show');
            Route::post('/auth/onboarding', [AuthController::class, 'onboarding'])->middleware('throttle:5,1')->name('auth.onboarding');
        });

        // Everything below runs inside a fashion house.
        Route::middleware(['api.tenant', 'plan'])->group(function () {
            Route::get('/tenants/current', [TenantController::class, 'current'])->name('tenants.current');

            // Staff app home, people, fittings calendar and notifications
            Route::get('/dashboard', [StaffAppController::class, 'dashboard'])->name('dashboard');
            Route::get('/staff', [StaffAppController::class, 'staff'])->name('staff.index');
            Route::get('/fittings', [StaffAppController::class, 'fittings'])->name('fittings.index');
            Route::get('/notifications', [StaffAppController::class, 'notifications'])->name('notifications.index');
            Route::post('/notifications/read-all', [StaffAppController::class, 'readAllNotifications'])->name('notifications.read-all');
            Route::post('/notifications/{id}/read', [StaffAppController::class, 'readNotification'])->name('notifications.read');

            // Customers (SRS §8.2)
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::post('/customers', [CustomerController::class, 'store'])->middleware('idempotent')->name('customers.store');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::get('/customers/{customer}/timeline', [CustomerController::class, 'timeline'])->name('customers.timeline');
            Route::post('/customers/{customer}/follow-ups', [CustomerController::class, 'storeFollowUp'])->middleware('idempotent')->name('customers.follow-ups.store');

            // Measurements (SRS §8.2)
            Route::get('/measurement-templates', [MeasurementController::class, 'templates'])->name('measurement-templates.index');
            Route::post('/measurement-templates/quick', [MeasurementController::class, 'quickTemplate'])->middleware('idempotent')->name('measurement-templates.quick');
            Route::get('/measurement-parameters', [MeasurementController::class, 'parameters'])->name('measurement-parameters.index');
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

            // Quotes, orders, invoices and payments (SRS §8.2)
            Route::get('/quotations', [OrderController::class, 'quotations'])->name('quotations.index');
            Route::post('/quotations', [OrderController::class, 'storeQuotation'])->middleware('idempotent')->name('quotations.store');
            Route::get('/quotations/{quotation}', [OrderController::class, 'quotation'])->name('quotations.show');
            Route::post('/quotations/{quotation}/accept', [OrderController::class, 'accept'])->middleware('idempotent')->name('quotations.accept');
            Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
            Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent')->name('orders.store');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::patch('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
            Route::post('/orders/{order}/status-events', [OrderController::class, 'statusEvent'])->name('orders.status-events');
            Route::post('/invoices', [OrderController::class, 'storeInvoice'])->middleware('idempotent')->name('invoices.store');
            Route::post('/invoices/{invoice}/issue', [OrderController::class, 'issueInvoice'])->name('invoices.issue');
            Route::post('/payments', [OrderController::class, 'storePayment'])->middleware('idempotent')->name('payments.store');
            Route::post('/payments/{payment}/allocate', [OrderController::class, 'allocate'])->middleware('idempotent')->name('payments.allocate');
            Route::post('/payments/{payment}/refund', [OrderController::class, 'refund'])->middleware('idempotent')->name('payments.refund');

            // Point of sale (SRS §8.2)
            Route::get('/pos/shifts/current', [PosController::class, 'current'])->name('pos.shifts.current');
            Route::post('/pos/shifts/open', [PosController::class, 'open'])->middleware('idempotent')->name('pos.shifts.open');
            Route::post('/pos/shifts/{shift}/close', [PosController::class, 'close'])->middleware('idempotent')->name('pos.shifts.close');
            Route::post('/pos/sales', [PosController::class, 'storeSale'])->name('pos.sales.store');
            Route::get('/pos/sales/{sale}', [PosController::class, 'showSale'])->name('pos.sales.show');

            // Production (SRS §8.2)
            Route::get('/production/tasks', [ProductionController::class, 'tasks'])->name('production.tasks.index');
            Route::get('/production/tasks/{task}', [ProductionController::class, 'task'])->name('production.tasks.show');
            Route::post('/production/tasks/{task}/events', [ProductionController::class, 'event'])->middleware('idempotent')->name('production.tasks.events');
            Route::post('/fittings', [ProductionController::class, 'storeFitting'])->middleware('idempotent')->name('fittings.store');
            Route::post('/fittings/{fitting}/record', [ProductionController::class, 'recordFitting'])->middleware('idempotent')->name('fittings.record');
            Route::post('/quality-checks', [ProductionController::class, 'storeQualityCheck'])->middleware('idempotent')->name('quality-checks.store');
            Route::post('/alteration-tasks', [ProductionController::class, 'storeAlteration'])->middleware('idempotent')->name('alteration-tasks.store');
            Route::post('/deliveries', [ProductionController::class, 'storeDelivery'])->middleware('idempotent')->name('deliveries.store');

            // Projects (SRS §8.2)
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::post('/projects', [ProjectController::class, 'store'])->middleware('idempotent')->name('projects.store');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::post('/projects/{project}/participants', [ProjectController::class, 'storeParticipants'])->middleware('idempotent')->name('projects.participants.store');
            Route::post('/projects/{project}/garments', [ProjectController::class, 'storeGarments'])->middleware('idempotent')->name('projects.garments.store');
            Route::post('/projects/{project}/milestones', [ProjectController::class, 'storeMilestone'])->middleware('idempotent')->name('projects.milestones.store');
            Route::post('/projects/{project}/milestones/{milestone}/publish', [ProjectController::class, 'publishMilestone'])->name('projects.milestones.publish');
            Route::post('/projects/{project}/updates', [ProjectController::class, 'storeUpdate'])->middleware('idempotent')->name('projects.updates.store');

            // Reports (SRS §8.2) — same permissions, plan and branch limits as the screens.
            Route::get('/reports', [\App\Http\Controllers\Api\V1\ReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/{report}', [\App\Http\Controllers\Api\V1\ReportController::class, 'show'])->name('reports.show');

            // QR attendance: staff scan the entrance code coming in and going out.
            Route::post('/attendance/scan', [AttendanceController::class, 'scan'])->middleware('throttle:20,1')->name('attendance.scan');
            Route::get('/attendance/me', [AttendanceController::class, 'me'])->name('attendance.me');
            Route::get('/attendance/code', [AttendanceController::class, 'code'])->name('attendance.code');
            Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::post('/attendance', [AttendanceController::class, 'store'])->middleware('idempotent')->name('attendance.store');
        });
    });
});
