<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| WMS スケジューラ設定
|--------------------------------------------------------------------------
|
| 本番環境では以下のcron設定が必要です:
| * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
| スケジュール一覧:
| ┌────────────────────────────────────┬──────────────────┬──────────────────────────────────────────────────────┐
| │ コマンド                           │ スケジュール     │ 内容                                                 │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:generate-waves                 │ 毎日 06/07/08時  │ Wave生成                                             │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:sync-monthly-safety-stocks     │ 毎月末日 01:30   │ 月別安全在庫→item_contractors.safety_stockに同期      │
| │                                    │                  │ use_safety_stock_auto_update=falseのレコードはスキップ │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:generate-calendars --months=3  │ 毎月1日 01:00    │ 倉庫別営業日カレンダー生成（3ヶ月分）                 │
| │                                    │                  │ 到着予定日の休日スキップ計算に使用                     │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:auto-order-scheduled           │ 5分ごと          │ 仕入先別の発注・移動候補を自動生成                     │
| │                                    │                  │ wms_contractor_settings.auto_order_generation_time    │
| │                                    │                  │ に基づきスナップショット生成＋候補計算を実行           │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:auto-order-transmit            │ 5分ごと          │ 送信時刻に基づく自動送信                               │
| │                                    │                  │ wms_contractor_settings.transmission_time             │
| │                                    │                  │ に基づきPENDING/APPROVED→確定→ファイル生成→送信      │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:incoming-receive-scheduled      │ 5分ごと          │ 入荷データ自動受信                                     │
| │                                    │                  │ wms_contractor_settings.receive_time                  │
| │                                    │                  │ に基づきJXデータ取得→パース→照合を実行               │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:switch-delivery-course         │ 15分ごと         │ 得意先の配送コースを時間帯で自動切替                   │
| │                                    │                  │ wms_buyer_delivery_course_switch_settingsに基づく      │
| ├────────────────────────────────────┼──────────────────┼──────────────────────────────────────────────────────┤
| │ wms:import-holidays                │ 毎年1月1日 03:00 │ 翌年の祝日データをインポート                           │
| │                                    │                  │ 倉庫カレンダー生成の元データとして使用                 │
| └────────────────────────────────────┴──────────────────┴──────────────────────────────────────────────────────┘
|
*/

// ======================================================
// 全スケジュール一時停止中（2026-04-28）
// ======================================================

// // Wave生成 (毎日 6:00, 7:00, 8:00)
// Schedule::command('wms:generate-waves')
//     ->dailyAt('06:00')
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/wms-generate-waves.log'));
//
// Schedule::command('wms:generate-waves')
//     ->dailyAt('07:00')
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/wms-generate-waves.log'));
//
// Schedule::command('wms:generate-waves')
//     ->dailyAt('08:00')
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/wms-generate-waves.log'));

// // 月別安全在庫の同期 (毎月末日 4:30)
// // ※ 翌月の発注点を事前に同期（翌日から適用されるため）
// Schedule::command('wms:sync-monthly-safety-stocks')
//     ->dailyAt('01:30')
//     ->when(fn () => now()->isLastOfMonth())
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/auto-order-monthly-safety-stocks.log'));

// // 倉庫カレンダー生成 (毎月1日 4:00)
// Schedule::command('wms:generate-calendars --months=3')
//     ->monthlyOn(1, '01:00')
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/auto-order-calendars.log'));

// 仕入先別自動発注スケジューラー (5分間隔)
Schedule::command('wms:auto-order-scheduled')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/auto-order-scheduled.log'));

// // 仕入先別自動送信スケジューラー (5分間隔)
// // ※ 仕入先ごとのtransmission_timeに基づいて承認→確定→ファイル生成→送信を実行
// Schedule::command('wms:auto-order-transmit')
//     ->everyFiveMinutes()
//     ->onOneServer()
//     ->withoutOverlapping()
//     ->appendOutputTo(storage_path('logs/auto-order-transmit.log'));

// 入荷データ自動受信スケジューラー (5分間隔)
// ※ 仕入先ごとのreceive_timeに基づいてJXデータ取得→原本保存→パース→照合を実行
Schedule::command('wms:incoming-receive-scheduled')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/incoming-receive-scheduled.log'));

// // 配送コース時間切替 (15分ごと)
// Schedule::command('wms:switch-delivery-course')
//     ->everyFifteenMinutes()
//     ->onOneServer()
//     ->withoutOverlapping()
//     ->appendOutputTo(storage_path('logs/delivery-course-switch.log'));

// // 祝日データインポート (毎年1月1日 3:00)
// // ※ 年間の祝日データを取得・更新
// Schedule::command('wms:import-holidays --year='.(date('Y') + 1))
//     ->yearlyOn(1, 1, '03:00')
//     ->withoutOverlapping()
//     ->onOneServer()
//     ->appendOutputTo(storage_path('logs/auto-order-holidays.log'));

/*
 * 定期在庫スナップショット (朝・夕)
 */

Schedule::command('wms:snapshot-stocks --time=morning')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/wms-stock-snapshot.log'));

Schedule::command('wms:snapshot-stocks --time=evening')
    ->dailyAt('18:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/wms-stock-snapshot.log'));

Schedule::command('wms:snapshot-archive')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/wms-stock-snapshot-archive.log'));
