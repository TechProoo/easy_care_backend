<?php

use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    Route::middleware(['web', 'auth:web', 'can:reference-data.view'])->group(function (): void {
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    });
});
