<?php

use App\Http\Controllers\Catalogue\CategoryController;
use App\Http\Controllers\Catalogue\ProductController;
use App\Http\Controllers\Catalogue\StyleController;
use Illuminate\Support\Facades\Route;

/* Styles & ready-made catalogue — SRS FR-MEA-006, FR-POS-007. Loaded inside the auth group in routes/web.php. */

Route::get('/catalogue/categories', [CategoryController::class, 'index'])->name('catalogue-categories.index');
Route::post('/catalogue/categories', [CategoryController::class, 'store'])->name('catalogue-categories.store');
Route::put('/catalogue/categories/{category}', [CategoryController::class, 'update'])->name('catalogue-categories.update');
Route::delete('/catalogue/categories/{category}', [CategoryController::class, 'destroy'])->name('catalogue-categories.destroy');

Route::get('/styles', [StyleController::class, 'index'])->name('styles.index');
Route::get('/styles/create', [StyleController::class, 'create'])->name('styles.create');
Route::post('/styles', [StyleController::class, 'store'])->name('styles.store');
Route::get('/styles/{style}', [StyleController::class, 'show'])->name('styles.show');
Route::get('/styles/{style}/edit', [StyleController::class, 'edit'])->name('styles.edit');
Route::put('/styles/{style}', [StyleController::class, 'update'])->name('styles.update');
Route::post('/styles/{style}/draft', [StyleController::class, 'newDraft'])->name('styles.new-draft');
Route::post('/styles/{style}/publish', [StyleController::class, 'publish'])->name('styles.publish');
Route::delete('/styles/{style}/draft', [StyleController::class, 'discardDraft'])->name('styles.discard-draft');
Route::post('/styles/{style}/archive', [StyleController::class, 'archive'])->name('styles.archive');
Route::delete('/style-images/{image}', [StyleController::class, 'destroyImage'])->name('style-images.destroy');
Route::post('/style-images/{image}/cover', [StyleController::class, 'coverImage'])->name('style-images.cover');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
Route::post('/products', [ProductController::class, 'store'])->name('products.store');
Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
Route::post('/products/{product}/archive', [ProductController::class, 'archive'])->name('products.archive');
