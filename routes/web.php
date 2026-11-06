<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json([
        'application' => config('app.name'),
        'status' => 'running',
    ]);
})->name('home');
