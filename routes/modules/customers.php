<?php

use App\Http\Controllers\Crm\CustomerController;
use App\Http\Controllers\Crm\CustomerNoteController;
use App\Http\Controllers\Crm\FollowUpController;
use Illuminate\Support\Facades\Route;

/* Customers (CRM) — SRS §5.3. Loaded inside the auth group in routes/web.php. */

Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
Route::get('/customers/{customer}/merge', [CustomerController::class, 'mergeForm'])->name('customers.merge.form');
Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->name('customers.merge');
Route::post('/customers/{customer}/archive', [CustomerController::class, 'archive'])->name('customers.archive');
Route::resource('customers', CustomerController::class);

Route::post('/customers/{customer}/notes', [CustomerNoteController::class, 'store'])->name('customers.notes.store');
Route::delete('/customers/{customer}/notes/{note}', [CustomerNoteController::class, 'destroy'])->name('customers.notes.destroy');

Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('follow-ups.index');
Route::post('/customers/{customer}/follow-ups', [FollowUpController::class, 'store'])->name('customers.follow-ups.store');
Route::patch('/follow-ups/{followUp}', [FollowUpController::class, 'update'])->name('follow-ups.update');
