<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Platform\TenantController as PlatformTenantController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\TenantSettingsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

Auth::routes(['verify' => true]);

// CSRF refresh (used by long-open pages)
Route::get('/refresh-csrf', function () {
    abort_unless(request()->ajax(), 404);
    Session::regenerateToken();

    return response()->json(['csrf_token' => csrf_token()]);
})->name('refresh.csrf');

// Customer measurement links (no login; token + expiry + rate limit — SRS §5.5)
Route::get('/m/{token}', [\App\Http\Controllers\Measurements\PublicMeasurementController::class, 'form'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('measure.form');
Route::post('/m/{token}', [\App\Http\Controllers\Measurements\PublicMeasurementController::class, 'submit'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('measure.submit');

/*
|--------------------------------------------------------------------------
| Signed-in (tenant context is set by ResolveTenant in the web group)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('spotlight.search');

    // ── My account ──────────────────────────────────────────────────────
    Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
        Route::get('/settings', 'settings')->name('settings');
        Route::post('/update-info', 'updateInfo')->name('update-info');
        Route::post('/update-avatar', 'updateAvatar')->name('update-avatar');
        Route::post('/update-email', 'updateEmail')->middleware('throttle:10,1')->name('update-email');
        Route::post('/update-password', 'updatePassword')->middleware('throttle:10,1')->name('update-password');
    });
    Route::post('/switch-tenant', [ProfileController::class, 'switchTenant'])->name('tenant.switch');

    // ── Notifications ───────────────────────────────────────────────────
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    // ── Users, roles & permissions ──────────────────────────────────────
    Route::get('/users/roles', [UserController::class, 'roles'])->name('users.roles');
    Route::resource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::post('roles/bulk-remove-users', [RoleController::class, 'bulkRemoveUsers'])->name('roles.bulkremoveusers');
    Route::get('/roles/{role}/users', [RoleController::class, 'getRoleUsers'])->name('roles.users');
    Route::get('/roles/{role}/add-users', [RoleController::class, 'adduser'])->name('roles.adduser');
    Route::post('/roles/assign-users', [RoleController::class, 'updateuserrole'])->name('roles.updateuserrole');
    Route::delete('/roles/remove-user/{userid}/{roleid}', [RoleController::class, 'removeuserrole'])
        ->whereNumber(['userid', 'roleid'])->name('roles.removeuserrole');
    Route::resource('roles', RoleController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    // ── Fashion house settings ──────────────────────────────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/house', [TenantSettingsController::class, 'edit'])->name('tenant.edit');
        Route::put('/house', [TenantSettingsController::class, 'update'])->name('tenant.update');
        Route::resource('branches', BranchController::class)->only(['index', 'store', 'update', 'destroy']);
    });

    // ── Platform operator (multi-tenant mode) ───────────────────────────
    Route::prefix('platform')->name('platform.')->group(function () {
        Route::get('/tenants', [PlatformTenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [PlatformTenantController::class, 'store'])->name('tenants.store');
        Route::patch('/tenants/{tenant}/status', [PlatformTenantController::class, 'updateStatus'])->name('tenants.status');
    });

    // ── Business modules (SRS roadmap), one route file each ─────────────
    require __DIR__ . '/modules/customers.php';
    require __DIR__ . '/modules/measurements.php';
});
