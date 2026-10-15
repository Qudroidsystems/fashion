<?php

use App\Http\Controllers\Inventory\AdjustmentController;
use App\Http\Controllers\Inventory\GoodsReceiptController;
use App\Http\Controllers\Inventory\ItemController;
use App\Http\Controllers\Inventory\MaterialIssueController;
use App\Http\Controllers\Inventory\PurchaseOrderController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Inventory\TransferController;
use Illuminate\Support\Facades\Route;

/* Inventory & procurement — SRS §5.6. Loaded inside the auth group in routes/web.php. */

Route::get('/inventory', [ItemController::class, 'index'])->name('inventory.index');
Route::get('/inventory/movements', [ItemController::class, 'movements'])->name('inventory.movements');
Route::get('/inventory/items/create', [ItemController::class, 'create'])->name('inventory.items.create');
Route::post('/inventory/items', [ItemController::class, 'store'])->name('inventory.items.store');
Route::get('/inventory/items/{item}', [ItemController::class, 'show'])->name('inventory.items.show');
Route::get('/inventory/items/{item}/edit', [ItemController::class, 'edit'])->name('inventory.items.edit');
Route::put('/inventory/items/{item}', [ItemController::class, 'update'])->name('inventory.items.update');
Route::post('/inventory/items/{item}/reservations', [ItemController::class, 'reserve'])->name('inventory.reservations.store');
Route::post('/inventory/reservations/{reservation}/release', [ItemController::class, 'release'])->name('inventory.reservations.release');

Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
Route::get('/suppliers/{supplier}', [SupplierController::class, 'edit'])->name('suppliers.edit');
Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit');
Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
Route::post('/purchase-orders/{purchaseOrder}/reopen', [PurchaseOrderController::class, 'reopen'])->name('purchase-orders.reopen');
Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
Route::get('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receiveForm'])->name('purchase-orders.receive');
Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive.store');

Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
Route::get('/goods-receipts/create', [GoodsReceiptController::class, 'create'])->name('goods-receipts.create');
Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->name('goods-receipts.store');
Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show');

Route::get('/stock-transfers', [TransferController::class, 'index'])->name('stock-transfers.index');
Route::get('/stock-transfers/create', [TransferController::class, 'create'])->name('stock-transfers.create');
Route::post('/stock-transfers', [TransferController::class, 'store'])->name('stock-transfers.store');
Route::get('/stock-transfers/{stockTransfer}', [TransferController::class, 'show'])->name('stock-transfers.show');

Route::get('/stock-adjustments', [AdjustmentController::class, 'index'])->name('stock-adjustments.index');
Route::get('/stock-adjustments/create', [AdjustmentController::class, 'create'])->name('stock-adjustments.create');
Route::post('/stock-adjustments', [AdjustmentController::class, 'store'])->name('stock-adjustments.store');
Route::get('/stock-adjustments/{stockAdjustment}', [AdjustmentController::class, 'show'])->name('stock-adjustments.show');
Route::get('/stock-adjustments/{stockAdjustment}/edit', [AdjustmentController::class, 'edit'])->name('stock-adjustments.edit');
Route::put('/stock-adjustments/{stockAdjustment}', [AdjustmentController::class, 'update'])->name('stock-adjustments.update');
Route::delete('/stock-adjustments/{stockAdjustment}', [AdjustmentController::class, 'destroy'])->name('stock-adjustments.destroy');
Route::post('/stock-adjustments/{stockAdjustment}/submit', [AdjustmentController::class, 'submit'])->name('stock-adjustments.submit');
Route::post('/stock-adjustments/{stockAdjustment}/approve', [AdjustmentController::class, 'approve'])->name('stock-adjustments.approve');
Route::post('/stock-adjustments/{stockAdjustment}/reject', [AdjustmentController::class, 'reject'])->name('stock-adjustments.reject');

Route::get('/material-issues', [MaterialIssueController::class, 'index'])->name('material-issues.index');
Route::get('/material-issues/create', [MaterialIssueController::class, 'create'])->name('material-issues.create');
Route::post('/material-issues', [MaterialIssueController::class, 'store'])->name('material-issues.store');
Route::get('/material-issues/{materialIssue}', [MaterialIssueController::class, 'show'])->name('material-issues.show');
Route::post('/material-issues/{materialIssue}/return', [MaterialIssueController::class, 'returnStock'])->name('material-issues.return');
