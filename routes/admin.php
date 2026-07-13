<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KioskController;
use App\Http\Controllers\Admin\PasswordResetRequestController;
use App\Http\Controllers\Admin\PhotoController;
use App\Http\Controllers\Admin\StudentController;
use Illuminate\Support\Facades\Route;

$prefix = config('admin.route_prefix', 'admin');

Route::middleware('web')->prefix($prefix)->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:admin-login')
            ->name('login.submit');
    });

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::get('/', fn () => redirect()->route('admin.dashboard'));
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('requests', [PasswordResetRequestController::class, 'index'])->name('requests.index');
        Route::get('requests/{passwordResetRequest}', [PasswordResetRequestController::class, 'show'])
            ->name('requests.show');

        Route::get('kiosks', [KioskController::class, 'index'])->name('kiosks.index');
        Route::post('kiosks', [KioskController::class, 'store'])->name('kiosks.store');
        Route::get('kiosks/{kiosk}', [KioskController::class, 'show'])->name('kiosks.show');
        Route::post('kiosks/{kiosk}/disable', [KioskController::class, 'disable'])->name('kiosks.disable');
        Route::post('kiosks/{kiosk}/enable', [KioskController::class, 'enable'])->name('kiosks.enable');
        Route::post('kiosks/{kiosk}/rotate-secret', [KioskController::class, 'rotateSecret'])
            ->name('kiosks.rotate-secret');
        Route::post('kiosks/{kiosk}/enrollment-code', [KioskController::class, 'issueEnrollmentCode'])
            ->name('kiosks.enrollment-code');
        Route::get('kiosks/{kiosk}/provisioning-bundle', [KioskController::class, 'provisioningBundle'])
            ->name('kiosks.provisioning-bundle');
        Route::delete('kiosks/{kiosk}', [KioskController::class, 'destroy'])->name('kiosks.destroy');

        Route::get('students', [StudentController::class, 'index'])->name('students.index');
        Route::get('students/{student}', [StudentController::class, 'show'])->name('students.show');
        Route::post('students/{student}/disable-reset', [StudentController::class, 'disableReset'])
            ->name('students.disable-reset');
        Route::post('students/{student}/enable-reset', [StudentController::class, 'enableReset'])
            ->name('students.enable-reset');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('reports/failed-attempts', [DashboardController::class, 'failedAttempts'])
            ->name('reports.failed-attempts');

        Route::get('photos/{studentPhoto}', [PhotoController::class, 'show'])->name('photos.show');
    });
});
