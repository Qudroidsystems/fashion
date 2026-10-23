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

/*
| House websites — registered first, so "/" on a house's subdomain is that house's site.
| {slug}.SITES_DOMAIN, the preview address /s/{slug}, or the house's own verified domain.
*/
$siteRoutes = function (string $prefix) {
    $c = \App\Http\Controllers\Sites\SiteController::class;
    Route::get('/', [$c, 'home'])->name($prefix . 'home');
    Route::get('/collection', [$c, 'collection'])->name($prefix . 'collection');
    Route::get('/collection/{style}', [$c, 'style'])->name($prefix . 'style');
    Route::get('/contact', [$c, 'contact'])->name($prefix . 'contact');
    Route::post('/contact', [$c, 'enquire'])->middleware('throttle:10,1')->name($prefix . 'enquire');

    // Online shop
    $shop = \App\Http\Controllers\Sites\ShopController::class;
    Route::get('/shop', [$shop, 'index'])->name($prefix . 'shop');
    Route::get('/product/{slug}', [$shop, 'show'])->where('slug', '[A-Za-z0-9\-]+')->name($prefix . 'product');
    Route::post('/product/{slug}/review', [$shop, 'review'])->where('slug', '[A-Za-z0-9\-]+')->middleware('throttle:5,10')->name($prefix . 'review');
    Route::get('/wishlist', [$shop, 'wishlist'])->name($prefix . 'wishlist');
    Route::post('/wishlist', [$shop, 'toggleWishlist'])->middleware('throttle:60,1')->name($prefix . 'wishlist.toggle');
    Route::get('/pages/{page}', [$shop, 'policy'])->name($prefix . 'policy');
    Route::match(['get', 'post'], '/track', [$shop, 'track'])->middleware('throttle:20,1')->name($prefix . 'track');

    $cart = \App\Http\Controllers\Sites\CartController::class;
    Route::get('/cart', [$cart, 'show'])->name($prefix . 'cart');
    Route::get('/cart/mini', [$cart, 'mini'])->name($prefix . 'cart.mini');
    Route::post('/cart/add', [$cart, 'add'])->middleware('throttle:60,1')->name($prefix . 'cart.add');
    Route::post('/cart/update', [$cart, 'update'])->middleware('throttle:120,1')->name($prefix . 'cart.update');
    Route::post('/cart/remove', [$cart, 'remove'])->name($prefix . 'cart.remove');
    Route::post('/cart/coupon', [$cart, 'coupon'])->middleware('throttle:20,1')->name($prefix . 'cart.coupon');

    $co = \App\Http\Controllers\Sites\CheckoutController::class;
    Route::get('/checkout', [$co, 'show'])->name($prefix . 'checkout');
    Route::post('/checkout/quote', [$co, 'quote'])->middleware('throttle:60,1')->name($prefix . 'checkout.quote');
    Route::post('/checkout', [$co, 'place'])->middleware('throttle:10,1')->name($prefix . 'checkout.place');
    Route::get('/checkout/paystack', [$co, 'paystackReturn'])->middleware('throttle:30,1')->name($prefix . 'checkout.paystack');
    Route::get('/checkout/stripe', [$co, 'stripeReturn'])->middleware('throttle:30,1')->name($prefix . 'checkout.stripe');
    Route::get('/order/{token}', [$co, 'order'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1')->name($prefix . 'order');
    Route::post('/order/{token}/pay', [$co, 'pay'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name($prefix . 'order.pay');
    Route::post('/order/{token}/proof', [$co, 'proof'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name($prefix . 'order.proof');
    Route::post('/order/{token}/cancel', [$co, 'cancel'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:5,1')->name($prefix . 'order.cancel');

    $acc = \App\Http\Controllers\Sites\AccountController::class;
    Route::get('/account/login', [$acc, 'loginForm'])->name($prefix . 'account.login');
    Route::post('/account/login', [$acc, 'login'])->middleware('throttle:20,1')->name($prefix . 'account.login.post');
    Route::get('/account/register', [$acc, 'registerForm'])->name($prefix . 'account.register');
    Route::post('/account/register', [$acc, 'register'])->middleware('throttle:10,1')->name($prefix . 'account.register.post');
    Route::post('/account/logout', [$acc, 'logout'])->name($prefix . 'account.logout');
    Route::get('/account/google', [$acc, 'google'])->middleware('throttle:20,1')->name($prefix . 'account.google');
    Route::get('/account/google/finish', [$acc, 'googleFinish'])->middleware('throttle:20,1')->name($prefix . 'account.google.finish');
    Route::get('/account/forgot', [$acc, 'forgotForm'])->name($prefix . 'account.forgot');
    Route::post('/account/forgot', [$acc, 'forgot'])->middleware('throttle:5,1')->name($prefix . 'account.forgot.post');
    Route::get('/account/reset/{token}', [$acc, 'resetForm'])->where('token', '[A-Za-z0-9]{64}')->name($prefix . 'account.reset');
    Route::post('/account/reset/{token}', [$acc, 'reset'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name($prefix . 'account.reset.post');
    Route::get('/account', [$acc, 'dashboard'])->name($prefix . 'account');
    Route::put('/account', [$acc, 'update'])->name($prefix . 'account.update');
    Route::get('/account/orders', [$acc, 'orders'])->name($prefix . 'account.orders');
    Route::get('/account/addresses', [$acc, 'addresses'])->name($prefix . 'account.addresses');
    Route::post('/account/addresses', [$acc, 'saveAddress'])->name($prefix . 'account.addresses.save');
    Route::delete('/account/addresses/{address}', [$acc, 'deleteAddress'])->whereNumber('address')->name($prefix . 'account.addresses.delete');
};
if ($sitesDomain = config('sites.domain')) {
    // Reserved labels (www, app, the platform's own subdomain…) never match, so those hosts keep the normal pages.
    $notReserved = '(?!(?:' . implode('|', array_map(fn ($r) => preg_quote($r, '#'), config('sites.reserved', []))) . ')\\.)';
    Route::domain('{site}.' . $sitesDomain)->where(['site' => $notReserved . '[a-z0-9][a-z0-9-]*'])->group(fn () => $siteRoutes('site.'));
}
Route::prefix('s/{site}')->where(['site' => '[a-z0-9][a-z0-9-]*'])->group(fn () => $siteRoutes('sitepath.'));

// Single-house installation with STORE_SINGLE_ROOT=true: the house's site and shop answer at the root of APP_URL.
$singleRoot = config('tenancy.mode') === 'single' && config('store.single_root') && config('tenancy.default_tenant');
if ($singleRoot) {
    $siteRoutes('root.');
    foreach (Route::getRoutes()->getRoutes() as $r) {
        if (str_starts_with((string) $r->getName(), 'root.')) {
            $r->defaults('site', config('tenancy.default_tenant'));
        }
    }
}

// Public landing page, for everyone. Signed-in users see "Open dashboard" instead of "Sign in";
// logging in still lands on /dashboard (LoginController::$redirectTo).
if (! $singleRoot) Route::get('/', fn () => view('landing', [
    'siteName'   => config('landing.brand') ?: config('app.name', 'Fashion House Platform'),
    'mobile'     => array_merge(['android_url' => null, 'ios_url' => null, 'is_demo' => true], (array) config('landing.app', [])),
    'signedIn'   => Auth::check(),
    // Plans as the platform super admin set them (empty before migrating, or in single-house mode).
    'plans'      => rescue(fn () => app(\App\Services\Billing\Entitlements::class)->enabled()
        ? \App\Models\Plan::where('is_active', true)->where('is_public', true)->ordered()->get() : collect(), collect(), false),
    'canSignUp'  => ! Auth::check() && config('tenancy.mode') !== 'single' && config('tenancy.self_signup') && \Illuminate\Support\Facades\Route::has('register'),
]))->name('home');

Auth::routes(['verify' => true]);

// Sign in / sign up with Google, and finishing a new account (its fashion house).
Route::get('/auth/google', [\App\Http\Controllers\Auth\GoogleController::class, 'redirect'])->middleware('throttle:20,1')->name('auth.google');
Route::get('/auth/google/callback', [\App\Http\Controllers\Auth\GoogleController::class, 'callback'])->middleware('throttle:20,1')->name('auth.google.callback');
// Shoppers on every house shop come back from Google here (one fixed redirect URI for all shops).
Route::get('/shop-auth/google/callback', [\App\Http\Controllers\Sites\ShopGoogleController::class, 'callback'])->middleware('throttle:30,1')->name('shop.google.callback');
Route::get('/welcome', [\App\Http\Controllers\Auth\OnboardingController::class, 'show'])->name('onboarding.show');
Route::post('/welcome', [\App\Http\Controllers\Auth\OnboardingController::class, 'store'])->name('onboarding.store');

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

// Online payments (SRS FR-ORD-008, FR-ADM-004): provider webhook per house and the customer's return page.
// Platform subscription payments (the platform's own Paystack account)
Route::post('/webhooks/billing/paystack', [\App\Http\Controllers\Billing\BillingController::class, 'webhook'])
    ->middleware('throttle:120,1')->name('webhooks.billing');

// Client project tracking page (no login; random revocable token — SRS FR-PRJ-006, scenario 39)
Route::get('/t/{token}', [\App\Http\Controllers\Projects\TrackingController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('projects.track');

Route::post('/webhooks/stripe/{tenant}', \App\Http\Controllers\Payments\StripeWebhookController::class)
    ->where('tenant', '[0-9A-Za-z]{26}')->middleware('throttle:120,1')->name('webhooks.stripe');
Route::post('/webhooks/paystack/{tenant}', [\App\Http\Controllers\Payments\PublicPaymentController::class, 'webhook'])
    ->where('tenant', '[0-9A-Za-z]{26}')->middleware('throttle:120,1')->name('webhooks.paystack');
Route::get('/pay/return', [\App\Http\Controllers\Payments\PublicPaymentController::class, 'return'])->middleware('throttle:30,1')->name('pay.return');

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
        Route::post('/tenants/{tenant}/plan', [\App\Http\Controllers\Platform\PlanController::class, 'setTenantPlan'])->name('tenants.plan');
        Route::get('/plans', [\App\Http\Controllers\Platform\PlanController::class, 'index'])->name('plans.index');
        Route::post('/plans', [\App\Http\Controllers\Platform\PlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{plan}', [\App\Http\Controllers\Platform\PlanController::class, 'update'])->name('plans.update');
    });

    // ── Business modules (SRS roadmap), one route file each ─────────────
    require __DIR__ . '/modules/customers.php';
    require __DIR__ . '/modules/measurements.php';
    require __DIR__ . '/modules/catalogue.php';
    require __DIR__ . '/modules/inventory.php';
    require __DIR__ . '/modules/orders.php';
    require __DIR__ . '/modules/pos.php';
    require __DIR__ . '/modules/production.php';
    require __DIR__ . '/modules/projects.php';
    require __DIR__ . '/modules/finance.php';
    require __DIR__ . '/modules/store.php';
    require __DIR__ . '/modules/attendance.php';

    // House website (settings and the enquiries it receives)
    Route::get('/website', [\App\Http\Controllers\Sites\WebsiteController::class, 'edit'])->name('website.edit');
    Route::put('/website', [\App\Http\Controllers\Sites\WebsiteController::class, 'update'])->name('website.update');
    Route::post('/website/domain-check', [\App\Http\Controllers\Sites\WebsiteController::class, 'checkDomain'])->name('website.domain-check');
    Route::get('/website/enquiries', [\App\Http\Controllers\Sites\WebsiteController::class, 'enquiries'])->name('site-enquiries.index');
    Route::patch('/website/enquiries/{enquiry}', [\App\Http\Controllers\Sites\WebsiteController::class, 'updateEnquiry'])->name('site-enquiries.update');
    Route::post('/website/enquiries/{enquiry}/convert', [\App\Http\Controllers\Sites\WebsiteController::class, 'convert'])->name('site-enquiries.convert');

    // Plan & billing (subscriptions, multi-house mode)
    Route::get('/billing', [\App\Http\Controllers\Billing\BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/plan', [\App\Http\Controllers\Billing\BillingController::class, 'choose'])->name('billing.choose');
    Route::get('/billing/return', [\App\Http\Controllers\Billing\BillingController::class, 'return'])->name('billing.return');
    Route::post('/billing/cancel', [\App\Http\Controllers\Billing\BillingController::class, 'cancel'])->name('billing.cancel');
});
