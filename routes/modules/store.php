<?php

use App\Http\Controllers\Store\CatalogController;
use App\Http\Controllers\Store\MarketingController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| Online store back office (plan feature "online_store", permissions in the "Online Store" group).
| Loaded inside the auth group in routes/web.php. The shop itself is in the site routes.
*/
Route::prefix('store')->name('store.')->group(function () {
    Route::get('/', [OrderController::class, 'dashboard'])->name('dashboard');

    // Online & WhatsApp orders
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{shopOrder}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{shopOrder}/accept', [OrderController::class, 'accept'])->name('orders.accept');
    Route::post('/orders/{shopOrder}/payment', [OrderController::class, 'payment'])->name('orders.payment');
    Route::post('/orders/{shopOrder}/proof/reject', [OrderController::class, 'rejectProof'])->name('orders.proof.reject');
    Route::post('/orders/{shopOrder}/advance', [OrderController::class, 'advance'])->name('orders.advance');
    Route::post('/orders/{shopOrder}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{shopOrder}/note', [OrderController::class, 'note'])->name('orders.note');
    Route::put('/orders/{shopOrder}/items', [OrderController::class, 'revise'])->name('orders.revise');
    Route::get('/variants', [OrderController::class, 'variants'])->name('variants');

    // Products in the shop: pictures, prices, sale prices, online details
    Route::get('/products', [CatalogController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [CatalogController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [CatalogController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/images', [CatalogController::class, 'upload'])->name('products.images.store');
    Route::post('/products/{product}/images/order', [CatalogController::class, 'sortImages'])->name('products.images.sort');
    Route::patch('/products/{product}/images/{image}', [CatalogController::class, 'updateImage'])->name('products.images.update');
    Route::delete('/products/{product}/images/{image}', [CatalogController::class, 'deleteImage'])->name('products.images.destroy');
    Route::post('/products/{product}/quick', [CatalogController::class, 'quick'])->name('products.quick');

    // Coupons, delivery zones, homepage banners, reviews, shoppers
    Route::get('/coupons', [MarketingController::class, 'coupons'])->name('coupons.index');
    Route::post('/coupons', [MarketingController::class, 'saveCoupon'])->name('coupons.store');
    Route::put('/coupons/{coupon}', [MarketingController::class, 'saveCoupon'])->name('coupons.update');
    Route::delete('/coupons/{coupon}', [MarketingController::class, 'deleteCoupon'])->name('coupons.destroy');
    Route::get('/shipping', [MarketingController::class, 'zones'])->name('shipping.index');
    Route::post('/shipping', [MarketingController::class, 'saveZone'])->name('shipping.store');
    Route::put('/shipping/{zone}', [MarketingController::class, 'saveZone'])->name('shipping.update');
    Route::delete('/shipping/{zone}', [MarketingController::class, 'deleteZone'])->name('shipping.destroy');
    Route::get('/banners', [MarketingController::class, 'banners'])->name('banners.index');
    Route::post('/banners', [MarketingController::class, 'saveBanner'])->name('banners.store');
    Route::post('/banners/{banner}', [MarketingController::class, 'saveBanner'])->name('banners.update');
    Route::delete('/banners/{banner}', [MarketingController::class, 'deleteBanner'])->name('banners.destroy');
    Route::get('/reviews', [MarketingController::class, 'reviews'])->name('reviews.index');
    Route::patch('/reviews/{review}', [MarketingController::class, 'moderate'])->name('reviews.update');
    Route::get('/shoppers', [MarketingController::class, 'accounts'])->name('accounts.index');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});
