<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\IncomingController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\PickingRouteController;
use App\Http\Controllers\Api\PickingTaskController;
use Illuminate\Support\Facades\Route;

// Internal API routes (for Filament pages, no API key required)
Route::get('/picking-routes', [PickingRouteController::class, 'getPickingRoute']);
Route::get('/walkable-areas', [PickingRouteController::class, 'getWalkableAreas']);

// All API routes require API key authentication
Route::middleware('api.key')->group(function () {
    // Public routes (no user authentication required, only API key)
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected routes (require both API key and user authentication)
    Route::middleware('auth:sanctum')->group(function () {
        // Authentication
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Master data endpoints
        Route::get('/master/warehouses', [MasterDataController::class, 'warehouses']);

        // Picking task endpoints
        Route::get('/picking/tasks', [PickingTaskController::class, 'index']);
        Route::get('/picking/tasks/{id}', [PickingTaskController::class, 'show']);
        Route::get('/picking/items/{id}', [PickingTaskController::class, 'showItem']);
        Route::post('/picking/tasks/{id}/start', [PickingTaskController::class, 'start']);
        Route::post('/picking/tasks/{itemResultId}/update', [PickingTaskController::class, 'updateItemResult']);
        Route::post('/picking/tasks/{itemResultId}/cancel', [PickingTaskController::class, 'cancelItemResult']);
        Route::post('/picking/tasks/{id}/complete', [PickingTaskController::class, 'complete']);

        // Incoming (入荷) endpoints
        Route::get('/incoming/schedules', [IncomingController::class, 'index']);
        Route::get('/incoming/schedules/{id}', [IncomingController::class, 'show']);
        Route::get('/incoming/work-items', [IncomingController::class, 'workItems']);
        Route::post('/incoming/work-items', [IncomingController::class, 'startWork']);
        Route::put('/incoming/work-items/{id}', [IncomingController::class, 'updateWork']);
        Route::post('/incoming/work-items/{id}/complete', [IncomingController::class, 'completeWork']);
        Route::delete('/incoming/work-items/{id}', [IncomingController::class, 'cancelWork']);
        Route::get('/incoming/locations', [IncomingController::class, 'searchLocations']);
    });
});
