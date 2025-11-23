<?php

namespace App\Filament\Widgets;

use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WmsOutboundOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $date = ClientSetting::systemDate() ?? now();
        $dateStr = $date->toDateString();

        // 1. ピッキング伝票数 (unique earning_id)
        $pickingSlipsCount = WmsPickingItemResult::query()
            ->join('wms_picking_tasks', 'wms_picking_item_results.picking_task_id', '=', 'wms_picking_tasks.id')
            ->whereDate('wms_picking_tasks.shipment_date', $dateStr)
            ->distinct('wms_picking_item_results.earning_id')
            ->count('wms_picking_item_results.earning_id');

        // 2. ピッキング商品数 (unique item_id)
        $pickingItemsCount = WmsPickingItemResult::query()
            ->join('wms_picking_tasks', 'wms_picking_item_results.picking_task_id', '=', 'wms_picking_tasks.id')
            ->whereDate('wms_picking_tasks.shipment_date', $dateStr)
            ->distinct('wms_picking_item_results.item_id')
            ->count('wms_picking_item_results.item_id');

        // 3. ピッキングStatus別の状態
        $pickingStatusCounts = WmsPickingItemResult::query()
            ->join('wms_picking_tasks', 'wms_picking_item_results.picking_task_id', '=', 'wms_picking_tasks.id')
            ->whereDate('wms_picking_tasks.shipment_date', $dateStr)
            ->select('wms_picking_item_results.status', DB::raw('count(*) as count'))
            ->groupBy('wms_picking_item_results.status')
            ->pluck('count', 'status')
            ->toArray();

        $pickingStatusDesc = collect($pickingStatusCounts)
            ->map(fn($count, $status) => "{$status}: {$count}")
            ->join(' / ');

        // 4. 欠品Status別の状態
        $shortageStatusCounts = WmsShortage::query()
            ->whereDate('shipment_date', $dateStr)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $shortageStatusDesc = collect($shortageStatusCounts)
            ->map(fn($count, $status) => "{$status}: {$count}")
            ->join(' / ');

        // 5. 配送コース数 (unique delivery_course_id)
        $deliveryCourseCount = WmsPickingTask::query()
            ->whereDate('shipment_date', $dateStr)
            ->distinct('delivery_course_id')
            ->count('delivery_course_id');

        // 6. 配送コース別印刷完了数
        $printedDeliveryCourseCount = WmsPickingTask::query()
            ->whereDate('shipment_date', $dateStr)
            ->where('print_requested_count', '>', 0)
            ->distinct('delivery_course_id')
            ->count('delivery_course_id');

        return [
            Stat::make('ピッキング伝票数', $pickingSlipsCount)
                ->description('対象日: ' . $dateStr)
                ->icon('heroicon-o-document-text'),

            Stat::make('ピッキング商品数', $pickingItemsCount)
                ->description('ユニーク商品数')
                ->icon('heroicon-o-cube'),

            Stat::make('ピッキング状況', array_sum($pickingStatusCounts) . ' 件')
                ->description($pickingStatusDesc ?: 'データなし')
                ->descriptionIcon('heroicon-o-chart-pie'),

            Stat::make('欠品状況', array_sum($shortageStatusCounts) . ' 件')
                ->description($shortageStatusDesc ?: 'データなし')
                ->color('danger')
                ->icon('heroicon-o-exclamation-triangle'),

            Stat::make('配送コース数', $deliveryCourseCount)
                ->description('全配送コース')
                ->icon('heroicon-o-truck'),

            Stat::make('印刷完了コース数', $printedDeliveryCourseCount)
                ->description("完了: {$printedDeliveryCourseCount} / 全: {$deliveryCourseCount}")
                ->color($printedDeliveryCourseCount === $deliveryCourseCount && $deliveryCourseCount > 0 ? 'success' : 'warning')
                ->icon('heroicon-o-printer'),
        ];
    }
}
