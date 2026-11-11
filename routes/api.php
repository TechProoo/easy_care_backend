<?php

use App\Http\Controllers\Api\V1\AccessControlController;
use App\Http\Controllers\Api\V1\AuditHistoryController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StaffDirectoryController;
use App\Http\Controllers\Api\V1\StaffManagementController;
use App\Http\Controllers\Api\V1\StaffRecoveryController;
use App\Http\Controllers\Api\V1\StaffSessionController;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\PreventAuthResponseCaching;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    Route::middleware(['web', PreventAuthResponseCaching::class])->group(function (): void {
        Route::get('auth/csrf-token', [StaffSessionController::class, 'csrf'])->name('auth.csrf');
        Route::post('auth/login', [StaffSessionController::class, 'store'])
            ->middleware('throttle:staff-login')->name('auth.login');
        Route::post('auth/password/reset', StaffRecoveryController::class)
            ->middleware('throttle:staff-recovery')->name('auth.password.reset');

        Route::middleware(['auth:web', EnsureStaffIsActive::class, 'auth.session'])->group(function (): void {
            Route::get('audit-events', AuditHistoryController::class)
                ->middleware('can:audit.view')->name('audit-events.index');
            Route::get('auth/me', [StaffSessionController::class, 'show'])->name('auth.me');
            Route::post('auth/logout', [StaffSessionController::class, 'destroy'])->name('auth.logout');
            Route::get('departments', [DepartmentController::class, 'index'])
                ->can('viewAny', Department::class)->name('departments.index');
            Route::get('departments/{department}', [DepartmentController::class, 'show'])
                ->can('view', 'department')->name('departments.show');
            Route::get('staff', [StaffDirectoryController::class, 'index'])
                ->can('viewAny', User::class)->name('staff.index');
            Route::get('staff/{user}', [StaffDirectoryController::class, 'show'])
                ->can('view', 'user')->name('staff.show');
            Route::get('access-control', AccessControlController::class)
                ->middleware('can:authorization.view')->name('access-control.index');
            Route::get('staff-management', [StaffManagementController::class, 'index'])
                ->can('manageAny', User::class)->name('staff-management.index');
            Route::get('staff-management/options', [StaffManagementController::class, 'options'])
                ->can('manageAny', User::class)->name('staff-management.options');
            Route::get('staff-management/{user}', [StaffManagementController::class, 'show'])
                ->can('manage', 'user')->name('staff-management.show');
            Route::middleware('throttle:staff-management')->group(function (): void {
                Route::post('staff-management', [StaffManagementController::class, 'store'])
                    ->can('create', User::class)->name('staff-management.store');
                Route::patch('staff-management/{user}', [StaffManagementController::class, 'update'])
                    ->can('update', 'user')->name('staff-management.update');
                Route::post('staff-management/{user}/password-reset', [StaffManagementController::class, 'issueReset'])
                    ->can('issuePasswordReset', 'user')->name('staff-management.password-reset');
            });
        });
    });
});
