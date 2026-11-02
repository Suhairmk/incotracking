<?php

use App\Http\Controllers\Admin\AdminAttendanceController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminEmployeeController;
use App\Http\Controllers\Admin\AdminLiveLocationController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\Admin\AdminRouteController;
use App\Http\Controllers\Admin\AdminShopController;
use App\Http\Controllers\Admin\AdminVisitController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Employee\DeviceTokenController;
use App\Http\Controllers\Employee\EmployeeAttendanceController;
use App\Http\Controllers\Employee\EmployeeDashboardController;
use App\Http\Controllers\Employee\EmployeeShopController;
use App\Http\Controllers\Employee\EmployeeTrackingController;
use App\Http\Controllers\Employee\EmployeeVisitController;
use App\Http\Controllers\PhotoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:password-reset');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('throttle:api')->group(function () {
            Route::get('photos/attendance/{attendance}/{type}', [PhotoController::class, 'attendance'])
                ->whereIn('type', [
                    'start', 'end', 'start_thumb', 'end_thumb',
                    'start_meter', 'end_meter', 'start_meter_thumb', 'end_meter_thumb',
                ])
                ->name('photos.attendance');
            Route::get('photos/visits/{visit}/{variant?}', [PhotoController::class, 'visit'])
                ->whereIn('variant', ['full', 'thumb'])
                ->name('photos.visit');
            Route::get('photos/profile/{user}/{variant?}', [PhotoController::class, 'profile'])
                ->whereIn('variant', ['full', 'thumb'])
                ->name('photos.profile');
        });

        Route::prefix('employee')->middleware(['role:employee', 'throttle:api'])->group(function () {
            Route::get('dashboard', EmployeeDashboardController::class);

            Route::get('shops', [EmployeeShopController::class, 'index']);
            Route::post('shops', [EmployeeShopController::class, 'store']);
            Route::get('shops/{shop}', [EmployeeShopController::class, 'show']);
            Route::put('shops/{shop}', [EmployeeShopController::class, 'update']);
            Route::delete('shops/{shop}', [EmployeeShopController::class, 'destroy']);

            Route::get('attendance/today', [EmployeeAttendanceController::class, 'today']);
            Route::get('attendance/history', [EmployeeAttendanceController::class, 'history']);
            Route::post('attendance/start', [EmployeeAttendanceController::class, 'start'])
                ->middleware('throttle:uploads');
            Route::post('attendance/end', [EmployeeAttendanceController::class, 'end'])
                ->middleware('throttle:uploads');
            Route::post('attendance/pause', [EmployeeAttendanceController::class, 'pause']);
            Route::post('attendance/resume', [EmployeeAttendanceController::class, 'resume']);
            Route::get('attendance/{attendance}', [EmployeeAttendanceController::class, 'show']);

            Route::post('tracking/batch', [EmployeeTrackingController::class, 'batch'])
                ->middleware('throttle:tracking');

            Route::get('visits', [EmployeeVisitController::class, 'index']);
            Route::post('visits', [EmployeeVisitController::class, 'store'])
                ->middleware('throttle:uploads');
            Route::get('visits/{visit}', [EmployeeVisitController::class, 'show']);

            Route::post('device-token', [DeviceTokenController::class, 'store']);
            Route::delete('device-token', [DeviceTokenController::class, 'destroy']);
        });

        Route::prefix('admin')->middleware(['role:admin', 'throttle:api'])->group(function () {
            Route::get('dashboard', AdminDashboardController::class);

            Route::get('employees', [AdminEmployeeController::class, 'index']);
            Route::post('employees', [AdminEmployeeController::class, 'store'])
                ->middleware('throttle:uploads');
            Route::get('employees/{employee}', [AdminEmployeeController::class, 'show']);
            Route::put('employees/{employee}', [AdminEmployeeController::class, 'update'])
                ->middleware('throttle:uploads');
            Route::delete('employees/{employee}', [AdminEmployeeController::class, 'destroy']);
            Route::post('employees/{employee}/reset-password', [AdminEmployeeController::class, 'resetPassword']);
            Route::post('employees/{employee}/photo', [AdminEmployeeController::class, 'uploadPhoto'])
                ->middleware('throttle:uploads');
            Route::get('employees/{employee}/route', [AdminRouteController::class, 'show']);

            Route::get('attendance', [AdminAttendanceController::class, 'index']);
            Route::get('attendance/{attendance}', [AdminAttendanceController::class, 'show']);

            Route::get('live-locations', [AdminLiveLocationController::class, 'index']);

            Route::get('visits', [AdminVisitController::class, 'index']);
            Route::get('visits/{visit}', [AdminVisitController::class, 'show']);

            Route::get('shops', [AdminShopController::class, 'index']);
            Route::get('shops/{shop}', [AdminShopController::class, 'show']);
            Route::delete('shops/{shop}', [AdminShopController::class, 'destroy']);

            Route::get('reports/daily', [AdminReportController::class, 'daily']);
            Route::get('reports/attendance', [AdminReportController::class, 'attendance']);
            Route::get('reports/distance', [AdminReportController::class, 'distance']);
            Route::get('reports/visits', [AdminReportController::class, 'visits']);

            Route::get('notifications', [AdminNotificationController::class, 'index']);
        });
    });
});
