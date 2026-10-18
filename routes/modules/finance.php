<?php

use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Reports\ReportController;
use Illuminate\Support\Facades\Route;

/* Expenses and reports — SRS §5.13. Loaded inside the auth group in routes/web.php. */

Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
Route::post('/expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');

Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
Route::get('/reports/{report}/print', [ReportController::class, 'print'])->name('reports.print');
Route::get('/reports/{report}/export/{format}', [ReportController::class, 'export'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->name('reports.export');
