<?php

use App\Http\Controllers\Orders\InvoiceController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\PaymentController;
use App\Http\Controllers\Orders\QuotationController;
use Illuminate\Support\Facades\Route;

/* Quotes, orders, invoices and payments — SRS §5.8. Loaded inside the auth group in routes/web.php. */

Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('quotations.edit');
Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
Route::post('/quotations/{quotation}/send', [QuotationController::class, 'send'])->name('quotations.send');
Route::post('/quotations/{quotation}/accept', [QuotationController::class, 'accept'])->name('quotations.accept');
Route::post('/quotations/{quotation}/decline', [QuotationController::class, 'decline'])->name('quotations.decline');

Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
Route::get('/orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update');
Route::post('/orders/{order}/status', [OrderController::class, 'transition'])->name('orders.transition');
Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
Route::post('/orders/{order}/reopen', [OrderController::class, 'reopen'])->name('orders.reopen');
Route::post('/orders/{order}/materials', [OrderController::class, 'addMaterial'])->name('orders.materials.store');
Route::delete('/order-materials/{requirement}', [OrderController::class, 'removeMaterial'])->name('order-materials.destroy');
Route::patch('/order-lines/{line}', [OrderController::class, 'updateLine'])->name('order-lines.update');

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
Route::post('/orders/{order}/invoices', [InvoiceController::class, 'fromOrder'])->name('invoices.from-order');
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
Route::post('/payments', [PaymentController::class, 'store'])->middleware('idempotent')->name('payments.store');
Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
Route::post('/payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('payments.allocate');
Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');
Route::post('/payments/{payment}/refunds', [PaymentController::class, 'requestRefund'])->name('payments.refund');
Route::post('/refunds/{refund}/approve', [PaymentController::class, 'approveRefund'])->name('refunds.approve');
Route::post('/refunds/{refund}/reject', [PaymentController::class, 'rejectRefund'])->name('refunds.reject');

// Online payment links (Paystack)
Route::post('/orders/{order}/payment-link', [\App\Http\Controllers\Payments\PaymentLinkController::class, 'forOrder'])->name('orders.payment-link');
Route::post('/invoices/{invoice}/payment-link', [\App\Http\Controllers\Payments\PaymentLinkController::class, 'forInvoice'])->name('invoices.payment-link');
Route::post('/payment-intents/{intent}/check', [\App\Http\Controllers\Payments\PaymentLinkController::class, 'check'])->name('payment-intents.check');
