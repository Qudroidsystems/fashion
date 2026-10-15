<?php

use App\Http\Controllers\Measurements\ParameterController;
use App\Http\Controllers\Measurements\RequestController;
use App\Http\Controllers\Measurements\SessionController;
use App\Http\Controllers\Measurements\TemplateController;
use Illuminate\Support\Facades\Route;

/* Measurements — SRS §5.4/§5.5. Loaded inside the auth group in routes/web.php. */

// Set-up
Route::get('/measurement-parameters', [ParameterController::class, 'index'])->name('measurement-parameters.index');
Route::post('/measurement-parameters', [ParameterController::class, 'store'])->name('measurement-parameters.store');
Route::put('/measurement-parameters/{parameter}', [ParameterController::class, 'update'])->name('measurement-parameters.update');

Route::get('/measurement-templates', [TemplateController::class, 'index'])->name('measurement-templates.index');
Route::post('/measurement-templates', [TemplateController::class, 'store'])->name('measurement-templates.store');
Route::get('/measurement-templates/{template}', [TemplateController::class, 'show'])->name('measurement-templates.show');
Route::put('/measurement-templates/{template}', [TemplateController::class, 'update'])->name('measurement-templates.update');
Route::post('/measurement-templates/{template}/draft', [TemplateController::class, 'edit'])->name('measurement-templates.draft');
Route::put('/measurement-templates/{template}/draft', [TemplateController::class, 'saveDraft'])->name('measurement-templates.save-draft');

// Sessions
Route::get('/measurements', [SessionController::class, 'index'])->name('measurements.index');
Route::get('/customers/{customer}/measurements/new', [SessionController::class, 'create'])->name('measurements.create');
Route::post('/customers/{customer}/measurements', [SessionController::class, 'store'])->name('measurements.store');
Route::get('/measurements/{session}', [SessionController::class, 'show'])->name('measurements.show');
Route::get('/measurements/{session}/edit', [SessionController::class, 'edit'])->name('measurements.edit');
Route::put('/measurements/{session}', [SessionController::class, 'update'])->name('measurements.update');
Route::delete('/measurements/{session}', [SessionController::class, 'destroy'])->name('measurements.destroy');
Route::post('/measurements/{session}/approve', [SessionController::class, 'approve'])->name('measurements.approve');
Route::post('/measurements/{session}/reject', [SessionController::class, 'reject'])->name('measurements.reject');
Route::post('/measurements/{session}/correct', [SessionController::class, 'correct'])->name('measurements.correct');

// Customer links (staff side)
Route::post('/customers/{customer}/measurement-requests', [RequestController::class, 'store'])->name('measurement-requests.store');
Route::post('/measurement-requests/{measurementRequest}/revoke', [RequestController::class, 'revoke'])->name('measurement-requests.revoke');
