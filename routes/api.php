<?php

use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StaffSessionController;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\PreventAuthResponseCaching;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    Route::middleware(['web', PreventAuthResponseCaching::class])->group(function (): void {
        Route::get('auth/csrf-token', [StaffSessionController::class, 'csrf'])->name('auth.csrf');
        Route::post('auth/login', [StaffSessionController::class, 'store'])
            ->middleware('throttle:staff-login')->name('auth.login');

        Route::middleware(['auth:web', EnsureStaffIsActive::class, 'auth.session'])->group(function (): void {
            Route::get('auth/me', [StaffSessionController::class, 'show'])->name('auth.me');
            Route::post('auth/logout', [StaffSessionController::class, 'destroy'])->name('auth.logout');
            Route::get('departments', [DepartmentController::class, 'index'])
                ->middleware('can:reference-data.view')->name('departments.index');
        });
    });
});
