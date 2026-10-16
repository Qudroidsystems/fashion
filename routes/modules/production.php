<?php

use App\Http\Controllers\Production\DeliveryController;
use App\Http\Controllers\Production\FittingController;
use App\Http\Controllers\Production\ProductionController;
use App\Http\Controllers\Production\SetupController;
use Illuminate\Support\Facades\Route;

/* Production, fittings, quality and delivery — SRS §5.9, §6.2. Loaded inside the auth group in routes/web.php. */

Route::get('/production', [ProductionController::class, 'board'])->name('production.board');
Route::get('/production/tasks/{task}', [ProductionController::class, 'show'])->name('production.tasks.show');
Route::post('/production/tasks/{task}/start', [ProductionController::class, 'start'])->name('production.tasks.start');
Route::post('/production/tasks/{task}/hold', [ProductionController::class, 'hold'])->name('production.tasks.hold');
Route::post('/production/tasks/{task}/complete', [ProductionController::class, 'complete'])->name('production.tasks.complete');
Route::post('/production/tasks/{task}/reopen', [ProductionController::class, 'reopen'])->name('production.tasks.reopen');
Route::post('/production/tasks/{task}/assign', [ProductionController::class, 'assign'])->name('production.tasks.assign');
Route::patch('/production/tasks/{task}', [ProductionController::class, 'update'])->name('production.tasks.update');
Route::post('/production/tasks/{task}/notes', [ProductionController::class, 'note'])->name('production.tasks.note');
Route::post('/production/tasks/{task}/quality-check', [ProductionController::class, 'qualityCheck'])->name('production.tasks.qc');
Route::post('/orders/{order}/production', [ProductionController::class, 'plan'])->name('orders.production.plan');
Route::post('/orders/{order}/alterations', [ProductionController::class, 'addAlteration'])->name('orders.alterations.store');

Route::get('/fittings', [FittingController::class, 'index'])->name('fittings.index');
Route::get('/fittings/create', [FittingController::class, 'create'])->name('fittings.create');
Route::post('/fittings', [FittingController::class, 'store'])->name('fittings.store');
Route::get('/fittings/{fitting}', [FittingController::class, 'show'])->name('fittings.show');
Route::post('/fittings/{fitting}/reschedule', [FittingController::class, 'reschedule'])->name('fittings.reschedule');
Route::post('/fittings/{fitting}/cancel', [FittingController::class, 'cancel'])->name('fittings.cancel');
Route::post('/fittings/{fitting}/record', [FittingController::class, 'record'])->name('fittings.record');

Route::post('/orders/{order}/deliveries', [DeliveryController::class, 'store'])->name('orders.deliveries.store');
Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');

Route::get('/production/setup', [SetupController::class, 'index'])->name('production.setup');
Route::get('/production/workflows/create', [SetupController::class, 'createWorkflow'])->name('production.workflows.create');
Route::post('/production/workflows', [SetupController::class, 'storeWorkflow'])->name('production.workflows.store');
Route::get('/production/workflows/{workflow}/edit', [SetupController::class, 'editWorkflow'])->name('production.workflows.edit');
Route::put('/production/workflows/{workflow}', [SetupController::class, 'updateWorkflow'])->name('production.workflows.update');
Route::post('/production/checklists', [SetupController::class, 'storeChecklist'])->name('production.checklists.store');
Route::put('/production/checklists/{checklist}', [SetupController::class, 'updateChecklist'])->name('production.checklists.update');
