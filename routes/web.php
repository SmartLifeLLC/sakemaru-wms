<?php

use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\PickingRouteController;
use App\Http\Controllers\Handy\HandyController;
use App\Http\Controllers\Handy\HandyIncomingController;
use App\Http\Controllers\Handy\HandyV2Controller;
use App\Http\Controllers\JxServerController;
use App\Http\Controllers\JxTransmissionLogController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Handy Terminal Web Apps
Route::get('/handy/login', [HandyController::class, 'login'])
    ->name('handy.login');
Route::get('/handy/home', [HandyController::class, 'home'])
    ->name('handy.home');
Route::get('/handy/incoming', [HandyIncomingController::class, 'index'])
    ->name('handy.incoming');
Route::get('/handy/outgoing', [HandyController::class, 'outgoing'])
    ->name('handy.outgoing');

// Handy V2 - Android Web App (SPA)
Route::get('/handy-v2/{any?}', [HandyV2Controller::class, 'index'])
    ->where('any', '.*')
    ->name('handy-v2');

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

// JX送受信ログファイルダウンロード（Filament認証）
Route::get('/jx-transmission-logs/{log}/download', [JxTransmissionLogController::class, 'download'])
    ->name('jx-transmission-logs.download')
    ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin']);

// JX-FINET テスト用受信サーバー（開発・テスト環境のみ）
if (app()->environment('local', 'testing', 'staging')) {
    Route::post('/jx-server', [JxServerController::class, 'handle'])
        ->name('jx-server.handle')
        ->middleware('jx.basic');

    // JXテストファイルダウンロード（S3）
    Route::get('/jx-test-files/{filename}/download', function (string $filename) {
        $path = "jx-test/{$filename}";

        if (! \Illuminate\Support\Facades\Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $url = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    })
        ->name('jx-test-files.download')
        ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin']);

    // JXテストサーバ受信ファイルダウンロード（S3）
    Route::get('/jx-server-files/download', function (\Illuminate\Http\Request $request) {
        $path = $request->query('path');

        if (! $path || ! str_starts_with($path, 'jx-server/')) {
            abort(400, '無効なパスです');
        }

        if (! \Illuminate\Support\Facades\Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $url = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    })
        ->name('jx-server-files.download')
        ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin']);

    // JX送信XMLファイルダウンロード（S3）
    Route::get('/jx-xml-files/download', function (\Illuminate\Http\Request $request) {
        $path = $request->query('path');

        if (! $path || ! str_starts_with($path, 'jx-client/requests/')) {
            abort(400, '無効なパスです');
        }

        if (! \Illuminate\Support\Facades\Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $url = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    })
        ->name('jx-xml-files.download')
        ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin']);
}
