<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 自動発注スケジューラ設定
|--------------------------------------------------------------------------
|
| 自動発注の定期実行設定
| 本番環境では以下のcron設定が必要です:
| * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

// 在庫スナップショット生成 (毎日 5:00)
Schedule::command('wms:snapshot-stocks')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-order-snapshot.log'));

// 倉庫カレンダー生成 (毎月1日 4:00)
Schedule::command('wms:generate-calendars --months=3')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-order-calendars.log'));

// 自動発注一括計算 (毎日 6:00)
// ※ スナップショット完了後に実行
Schedule::command('wms:auto-order-calculate --skip-snapshot')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-order-calculate.log'));

// 祝日データインポート (毎年1月1日 3:00)
// ※ 年間の祝日データを取得・更新
Schedule::command('wms:import-holidays --year=' . (date('Y') + 1))
    ->yearlyOn(1, 1, '03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/auto-order-holidays.log'));
