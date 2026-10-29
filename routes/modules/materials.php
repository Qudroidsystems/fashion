<?php

use App\Http\Controllers\Materials\MaterialController;
use App\Http\Controllers\Settings\MessagingController;
use Illuminate\Support\Facades\Route;

/* Customer materials (fabric and garments customers leave with the house) and house messaging. Loaded inside the auth group in routes/web.php. */

Route::get('/materials', [MaterialController::class, 'index'])->name('materials.index');
Route::get('/materials/create', [MaterialController::class, 'create'])->name('materials.create');
Route::post('/materials', [MaterialController::class, 'store'])->name('materials.store');
Route::post('/materials/return', [MaterialController::class, 'giveBack'])->name('materials.return');
Route::put('/materials/settings', [MaterialController::class, 'settings'])->name('materials.settings');
Route::get('/materials/{material}', [MaterialController::class, 'show'])->name('materials.show');
Route::get('/materials/{material}/edit', [MaterialController::class, 'edit'])->name('materials.edit');
Route::put('/materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
Route::get('/materials/{material}/tag', [MaterialController::class, 'tag'])->name('materials.tag');
Route::post('/materials/{material}/move', [MaterialController::class, 'move'])->name('materials.move');
Route::post('/materials/{material}/close', [MaterialController::class, 'close'])->name('materials.close');
Route::post('/materials/{material}/notes', [MaterialController::class, 'note'])->name('materials.note');
Route::post('/materials/{material}/photos', [MaterialController::class, 'photos'])->name('materials.photos');
Route::delete('/materials/{material}/photos/{index}', [MaterialController::class, 'removePhoto'])->whereNumber('index')->name('materials.photos.destroy');
Route::post('/customers/{customer}/materials/remind', [MaterialController::class, 'remind'])->name('customers.materials.remind');

Route::get('/settings/messaging', [MessagingController::class, 'edit'])->name('settings.messaging');
Route::put('/settings/messaging', [MessagingController::class, 'update'])->name('settings.messaging.update');
Route::post('/settings/messaging/test', [MessagingController::class, 'test'])->name('settings.messaging.test');
