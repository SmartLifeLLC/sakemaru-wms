<?php

use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\PickingRouteController;
use App\Http\Controllers\ErrorInquiryController;
use App\Http\Controllers\JxServerController;
use App\Http\Controllers\JxTransmissionLogController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::post('/admin/error-inquiries', ErrorInquiryController::class)
    ->name('admin.error-inquiries.store');

// Floor plan API routes (accessible from admin panel without API key)
Route::prefix('api')->middleware(['web', 'auth:web'])->group(function () {
    Route::middleware('sakemaru-permission:wms.floor-plan.view')->group(function () {
        Route::get('/warehouses', [FloorPlanController::class, 'getWarehouses']);
        Route::get('/warehouses/{warehouseId}/floors', [FloorPlanController::class, 'getFloors']);
        Route::get('/floors/{floorId}/zones', [FloorPlanController::class, 'getZones']);
        Route::get('/zones/{zoneId}/stocks', [FloorPlanController::class, 'getZoneStocks']);
        Route::get('/floors/{floorId}/unpositioned-locations', [FloorPlanController::class, 'getUnpositionedLocations']);
        Route::get('/floors/{floorId}/export-csv', [FloorPlanController::class, 'exportCSV']);
    });

    Route::middleware('sakemaru-permission:wms.floor-plan.edit')->group(function () {
        Route::post('/floors/{floorId}/zones', [FloorPlanController::class, 'saveZones']);
    });

    Route::middleware('sakemaru-permission:wms.picking-route.view')->group(function () {
        Route::get('/picking-routes', [PickingRouteController::class, 'getPickingRoute']);
    });
});

// JX送受信ログファイルダウンロード（Filament認証）
Route::get('/jx-transmission-logs/{log}/download', [JxTransmissionLogController::class, 'download'])
    ->name('jx-transmission-logs.download')
    ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin', 'sakemaru-permission:wms.jx-transmission-log.download']);

// JX送信XMLファイルダウンロード（S3）
Route::get('/jx-xml-files/download', function (\Illuminate\Http\Request $request) {
    $path = $request->query('path');

    if (! $path || ! str_starts_with($path, 'jx-client/')) {
        abort(400, '無効なパスです');
    }

    if (! \Illuminate\Support\Facades\Storage::disk('s3')->exists($path)) {
        abort(404);
    }

    $url = \Illuminate\Support\Facades\Storage::disk('s3')->temporaryUrl($path, now()->addHour());

    return redirect($url);
})
    ->name('jx-xml-files.download')
    ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin', 'sakemaru-permission:wms.jx-transmission-log.download']);

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
        ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin', 'sakemaru-permission:wms.jx-transmission-log.download']);

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
        ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin', 'sakemaru-permission:wms.jx-transmission-log.download']);
}

/*
 * 仮ピッキングリスト出力用の一時ダウンロード。
 * 生成済みPDFバイト列を Cache に格納したトークン経由でダウンロードする。
 * 一度ダウンロードすると削除される（Cache::pull）。
 */
Route::get('/admin/picking-list/temp-download/{token}', function (string $token) {
    $cached = \Illuminate\Support\Facades\Cache::pull("picking-list-temp-{$token}");
    if (! $cached) {
        abort(404);
    }

    return response($cached['content'])
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'attachment; filename="'.($cached['filename'] ?? 'picking-list.pdf').'"');
})
    ->name('picking-list.temp-download')
    ->middleware(['web', \Filament\Http\Middleware\Authenticate::class.':admin']);
