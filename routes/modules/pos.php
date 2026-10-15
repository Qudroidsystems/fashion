<?php

use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\ShiftController;
use Illuminate\Support\Facades\Route;

/* Point of sale — SRS §5.7, §6.3. Loaded inside the auth group in routes/web.php. */

Route::get('/pos', [PosController::class, 'terminal'])->name('pos.terminal');
Route::get('/pos/products', [PosController::class, 'products'])->middleware('throttle:240,1')->name('pos.products');
Route::get('/pos/customers', [PosController::class, 'customers'])->name('pos.customers');
Route::get('/pos/orders', [PosController::class, 'orders'])->name('pos.orders');
Route::post('/pos/sales', [PosController::class, 'store'])->name('pos.sales.store');
Route::get('/pos/sales', [PosController::class, 'sales'])->name('pos.sales.index');
Route::get('/pos/sales/{sale}', [PosController::class, 'show'])->name('pos.sales.show');
Route::get('/pos/sales/{sale}/receipt', [PosController::class, 'receipt'])->name('pos.sales.receipt');
Route::post('/pos/sales/{sale}/void', [PosController::class, 'void'])->name('pos.sales.void');
Route::post('/pos/sales/{sale}/return', [PosController::class, 'returnItems'])->name('pos.sales.return');

Route::get('/pos/shifts', [ShiftController::class, 'index'])->name('pos.shifts.index');
Route::post('/pos/shifts', [ShiftController::class, 'open'])->name('pos.shifts.open');
Route::get('/pos/shifts/{shift}', [ShiftController::class, 'show'])->name('pos.shifts.show');
Route::post('/pos/shifts/{shift}/cash', [ShiftController::class, 'movement'])->name('pos.shifts.cash');
Route::post('/pos/shifts/{shift}/close', [ShiftController::class, 'close'])->name('pos.shifts.close');
