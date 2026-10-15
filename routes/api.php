<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
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

            /*
            | Further module endpoints (measurement-sessions, orders, …) go here.
            */
        });
    });
});
