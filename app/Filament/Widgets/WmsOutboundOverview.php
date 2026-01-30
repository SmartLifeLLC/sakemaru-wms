<?php

namespace App\Filament\Widgets;

use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Services\WmsStatsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WmsOutboundOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $date = ClientSetting::systemDate() ?? now();
        $dateStr = $date->toDateString();

        // 統計サービスを使用してデータ取得
        $statsService = app(WmsStatsService::class);

        // 全倉庫の統計を集計
        $warehouses = Warehouse::where('is_active', true)->get();
        $totalStats = [
            'picking_slip_count' => 0,
            'picking_item_count' => 0,
            'unique_item_count' => 0,
            'stockout_unique_count' => 0,
            'stockout_total_count' => 0,
            'delivery_course_count' => 0,
            'total_ship_qty' => 0,
            'total_amount_ex' => 0,
            'total_amount_in' => 0,
            'total_opportunity_loss' => 0,
        ];

        foreach ($warehouses as $warehouse) {
            try {
                $stat = $statsService->getOrUpdateDailyStats($date, $warehouse->id);
                $totalStats['picking_slip_count'] += $stat->picking_slip_count;
                $totalStats['picking_item_count'] += $stat->picking_item_count;
                $totalStats['unique_item_count'] += $stat->unique_item_count;
                $totalStats['stockout_unique_count'] += $stat->stockout_unique_count;
                $totalStats['stockout_total_count'] += $stat->stockout_total_count;
                $totalStats['delivery_course_count'] += $stat->delivery_course_count;
                $totalStats['total_ship_qty'] += $stat->total_ship_qty;
                $totalStats['total_amount_ex'] += $stat->total_amount_ex;
                $totalStats['total_amount_in'] += $stat->total_amount_in;
                $totalStats['total_opportunity_loss'] += $stat->total_opportunity_loss;
            } catch (\Exception $e) {
                // エラーが発生した場合はログに記録してスキップ
                \Log::warning("Failed to get stats for warehouse {$warehouse->id}: ".$e->getMessage());
            }
        }

        return [
            Stat::make('ピッキング伝票数', number_format($totalStats['picking_slip_count']))
                ->description('対象日: '.$dateStr)
                ->descriptionIcon('heroicon-o-document-text')
                ->icon('heroicon-o-document-text')
                ->color('primary'),

            Stat::make('ピッキング商品数', number_format($totalStats['picking_item_count']))
                ->description('ユニーク: '.number_format($totalStats['unique_item_count']))
                ->descriptionIcon('heroicon-o-cube')
                ->icon('heroicon-o-cube')
                ->color('success'),

            Stat::make('合計出荷数量', number_format($totalStats['total_ship_qty']))
                ->description('総バラ数')
                ->descriptionIcon('heroicon-o-squares-2x2')
                ->icon('heroicon-o-squares-2x2')
                ->color('info'),

            Stat::make('配送コース数', number_format($totalStats['delivery_course_count']))
                ->description('全配送コース')
                ->descriptionIcon('heroicon-o-truck')
                ->icon('heroicon-o-truck')
                ->color('warning'),

            Stat::make('税込合計金額', '¥'.number_format($totalStats['total_amount_in']))
                ->description('税抜: ¥'.number_format($totalStats['total_amount_ex']))
                ->descriptionIcon('heroicon-o-currency-yen')
                ->icon('heroicon-o-currency-yen')
                ->color('success'),

            Stat::make('欠品状況', number_format($totalStats['stockout_total_count']).' 件')
                ->description('ユニーク: '.number_format($totalStats['stockout_unique_count']).' / 損失: ¥'.number_format($totalStats['total_opportunity_loss']))
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($totalStats['stockout_total_count'] > 0 ? 'danger' : 'success'),
        ];
    }
}
