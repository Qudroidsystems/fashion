<?php

use App\Http\Controllers\Attendance\AttendanceController;
use Illuminate\Support\Facades\Route;

/* QR attendance. Loaded inside the auth group in routes/web.php. */

Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
Route::get('/attendance/entrance', [AttendanceController::class, 'kiosk'])->name('attendance.kiosk');
Route::get('/attendance/entrance/code', [AttendanceController::class, 'kioskCode'])->name('attendance.kiosk.code');
Route::get('/attendance/print', [AttendanceController::class, 'printable'])->name('attendance.print');
Route::put('/attendance/settings', [AttendanceController::class, 'settings'])->name('attendance.settings');
Route::post('/attendance/printed-code', [AttendanceController::class, 'newPrintedCode'])->name('attendance.printed-code');
