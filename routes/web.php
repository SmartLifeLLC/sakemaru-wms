<?php

use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\PickingRouteController;
use App\Http\Controllers\JxServerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Floor plan API routes (accessible from admin panel without API key)
Route::prefix('api')->middleware(['web'])->group(function () {
    Route::get('/warehouses', [FloorPlanController::class, 'getWarehouses']);
    Route::get('/warehouses/{warehouseId}/floors', [FloorPlanController::class, 'getFloors']);
    Route::get('/floors/{floorId}/zones', [FloorPlanController::class, 'getZones']);
    Route::post('/floors/{floorId}/zones', [FloorPlanController::class, 'saveZones']);
    Route::get('/zones/{zoneId}/stocks', [FloorPlanController::class, 'getZoneStocks']);
    Route::get('/floors/{floorId}/unpositioned-locations', [FloorPlanController::class, 'getUnpositionedLocations']);
    Route::get('/floors/{floorId}/export-csv', [FloorPlanController::class, 'exportCSV']);

    // Picking route visualization API
    Route::get('/picking-routes', [PickingRouteController::class, 'getPickingRoute']);
});

// JX-FINET テスト用受信サーバー（開発・テスト環境のみ）
if (app()->environment('local', 'testing', 'staging')) {
    Route::post('/jx-server', [JxServerController::class, 'handle'])
        ->name('jx-server.handle')
        ->middleware('auth.basic');
}
