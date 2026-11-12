<?php

use App\Http\Controllers\Api\V1\AccessControlController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditHistoryController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PatientRecordController;
use App\Http\Controllers\Api\V1\ProtectedFileController;
use App\Http\Controllers\Api\V1\StaffDirectoryController;
use App\Http\Controllers\Api\V1\StaffManagementController;
use App\Http\Controllers\Api\V1\StaffRecoveryController;
use App\Http\Controllers\Api\V1\StaffSessionController;
use App\Http\Middleware\EnsureStaffIsActive;
use App\Http\Middleware\PreventAuthResponseCaching;
use App\Models\Department;
use App\Models\Patient;
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
            Route::prefix('appointments')->name('appointments.')->middleware('can:appointments.view')->group(function (): void {
                Route::get('options', [AppointmentController::class, 'options'])->name('options');
                Route::get('clinicians', [AppointmentController::class, 'clinicians'])->name('clinicians');
                Route::get('/', [AppointmentController::class, 'index'])->name('index');
                Route::get('{appointment}', [AppointmentController::class, 'show'])->where('appointment', '[1-9][0-9]{0,17}')->name('show');
                Route::get('{appointment}/history', [AppointmentController::class, 'history'])->where('appointment', '[1-9][0-9]{0,17}')->name('history');
                Route::middleware('throttle:appointment-writes')->group(function (): void {
                    Route::post('/', [AppointmentController::class, 'store'])->middleware('can:appointments.create')->name('store');
                    Route::patch('{appointment}', [AppointmentController::class, 'update'])->where('appointment', '[1-9][0-9]{0,17}')
                        ->middleware('can:appointments.update')->name('update');
                    foreach (['confirm', 'cancel', 'no-show'] as $action) {
                        Route::post('{appointment}/'.$action, [AppointmentController::class, 'action'])->where('appointment', '[1-9][0-9]{0,17}')
                            ->middleware('can:appointments.update')->name($action);
                    }
                });
            });
            Route::middleware('can:patients.view')->group(function (): void {
                Route::prefix('patients/{patient}')->where(['patient' => '[1-9][0-9]{0,17}', 'flag' => '[1-9][0-9]{0,17}', 'mergeRequest' => '[1-9][0-9]{0,17}'])
                    ->name('patients.records.')->group(function (): void {
                        Route::get('flags', [PatientRecordController::class, 'flags'])->middleware('can:patients.flags.view')->name('flags.index');
                        Route::get('card-history', [PatientRecordController::class, 'cards'])->middleware('can:patients.cards.manage')->name('cards.index');
                        Route::get('access-state', [PatientRecordController::class, 'access'])->middleware('can:patients.access.manage')->name('access.show');
                        Route::get('merge-requests', [PatientRecordController::class, 'merges'])->can('reviewMerges', Patient::class)->name('merges.index');
                        Route::middleware('throttle:patient-writes')->group(function (): void {
                            Route::post('flags', [PatientRecordController::class, 'write'])->middleware(['can:patients.flags.view', 'can:patients.flags.manage'])->name('flags.store');
                            Route::post('flags/{flag}/resolve', [PatientRecordController::class, 'write'])->middleware(['can:patients.flags.view', 'can:patients.flags.manage'])->name('flags.resolve');
                            Route::post('access/restrict', [PatientRecordController::class, 'write'])->middleware('can:patients.access.manage')->name('access.restrict');
                            Route::post('access/restore', [PatientRecordController::class, 'write'])->middleware('can:patients.access.manage')->name('access.restore');
                            Route::post('card', [PatientRecordController::class, 'write'])->middleware('can:patients.cards.manage')->name('cards.change');
                            Route::post('merge-requests', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.request')->name('merges.request');
                            Route::post('merge-requests/{mergeRequest}/approve', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.approve')->name('merges.approve');
                            Route::post('merge-requests/{mergeRequest}/reject', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.approve')->name('merges.reject');
                            Route::post('merge-requests/{mergeRequest}/cancel', [PatientRecordController::class, 'write'])->middleware('can:patients.merges.request')->name('merges.cancel');
                        });
                    });
                Route::get('patients/options', [PatientController::class, 'options'])->name('patients.options');
                Route::post('patients/duplicate-check', [PatientController::class, 'duplicates'])->name('patients.duplicates');
                Route::get('patients', [PatientController::class, 'index'])->name('patients.index');
                Route::get('patients/{patient}', [PatientController::class, 'show'])
                    ->where('patient', '[1-9][0-9]{0,17}')->name('patients.show');
                Route::post('patients', [PatientController::class, 'store'])
                    ->middleware(['can:patients.create', 'throttle:patient-writes'])->name('patients.store');
                Route::patch('patients/{patient}', [PatientController::class, 'update'])
                    ->where('patient', '[1-9][0-9]{0,17}')
                    ->middleware(['can:patients.update', 'throttle:patient-writes'])->name('patients.update');
            });
            Route::middleware('can:files.view_own')->group(function (): void {
                Route::get('files', [ProtectedFileController::class, 'index'])->name('files.index');
                Route::post('files', [ProtectedFileController::class, 'store'])
                    ->middleware(['can:files.upload', 'throttle:protected-files-upload'])->name('files.store');
                Route::get('files/{file}', [ProtectedFileController::class, 'show'])
                    ->where('file', '[1-9][0-9]{0,17}')->name('files.show');
                Route::get('files/{file}/download', [ProtectedFileController::class, 'download'])
                    ->where('file', '[1-9][0-9]{0,17}')->name('files.download');
            });
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
