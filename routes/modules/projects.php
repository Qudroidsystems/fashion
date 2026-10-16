<?php

use App\Http\Controllers\Projects\ParticipantController;
use App\Http\Controllers\Projects\PlanController;
use App\Http\Controllers\Projects\ProjectController;
use Illuminate\Support\Facades\Route;

/* Weddings, events and bulk projects — SRS §5.10, §6.4. Loaded inside the auth group in routes/web.php. */

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
Route::post('/projects/{project}/confirm', [ProjectController::class, 'confirm'])->name('projects.confirm');
Route::post('/projects/{project}/cancel', [ProjectController::class, 'cancel'])->name('projects.cancel');
Route::post('/projects/{project}/complete', [ProjectController::class, 'complete'])->name('projects.complete');
Route::post('/projects/{project}/tracking', [ProjectController::class, 'tracking'])->name('projects.tracking');

Route::post('/projects/{project}/participants', [ParticipantController::class, 'store'])->name('projects.participants.store');
Route::put('/projects/{project}/participants/{participant}', [ParticipantController::class, 'update'])->name('projects.participants.update');
Route::post('/projects/{project}/participants/{participant}/withdraw', [ParticipantController::class, 'withdraw'])->name('projects.participants.withdraw');
Route::get('/projects/{project}/participants/{participant}/measure', [ParticipantController::class, 'measure'])->name('projects.participants.measure');
Route::post('/projects/{project}/garments', [ParticipantController::class, 'addGarments'])->name('projects.garments.store');
Route::delete('/projects/{project}/garments/{line}', [ParticipantController::class, 'removeGarment'])->name('projects.garments.destroy');
Route::post('/projects/{project}/garments/{line}/measurements', [ParticipantController::class, 'linkMeasurement'])->name('projects.garments.measurements');

Route::post('/projects/{project}/milestones', [PlanController::class, 'storeMilestone'])->name('projects.milestones.store');
Route::put('/projects/{project}/milestones/{milestone}', [PlanController::class, 'updateMilestone'])->name('projects.milestones.update');
Route::delete('/projects/{project}/milestones/{milestone}', [PlanController::class, 'destroyMilestone'])->name('projects.milestones.destroy');
Route::post('/projects/{project}/milestones/{milestone}/publish', [PlanController::class, 'publishMilestone'])->name('projects.milestones.publish');
Route::post('/projects/{project}/updates', [PlanController::class, 'storeUpdate'])->name('projects.updates.store');
Route::post('/projects/{project}/updates/{update}/publish', [PlanController::class, 'publishUpdate'])->name('projects.updates.publish');
Route::post('/projects/{project}/updates/{update}/unpublish', [PlanController::class, 'unpublishUpdate'])->name('projects.updates.unpublish');
Route::post('/projects/{project}/budget', [PlanController::class, 'storeBudget'])->name('projects.budget.store');
Route::delete('/projects/{project}/budget/{line}', [PlanController::class, 'destroyBudget'])->name('projects.budget.destroy');
Route::post('/projects/{project}/expenses', [PlanController::class, 'storeExpense'])->name('projects.expenses.store');
Route::delete('/projects/{project}/expenses/{expense}', [PlanController::class, 'destroyExpense'])->name('projects.expenses.destroy');
Route::post('/projects/{project}/schedule', [PlanController::class, 'storeSchedule'])->name('projects.schedule.store');
Route::delete('/projects/{project}/schedule/{schedule}', [PlanController::class, 'destroySchedule'])->name('projects.schedule.destroy');
