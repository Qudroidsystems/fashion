<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
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

            /*
            | Further module endpoints (styles, orders, …) go here.
            */
        });
    });
});
