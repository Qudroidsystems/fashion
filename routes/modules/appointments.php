<?php

use App\Http\Controllers\Appointments\AppointmentController;
use Illuminate\Support\Facades\Route;

/* Appointments diary. Loaded inside the auth group in routes/web.php. */

Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
Route::get('/appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'status'])->name('appointments.status');
