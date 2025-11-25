<?php

namespace App\Services;

use App\Models\WmsDailyStat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WmsStatsService
{
    /**
     * 指定日付・倉庫の統計データを取得または更新
     * 30分ルール: 最終集計から30分未満なら既存データを返す
     *
     * @param Carbon $date
     * @param int $warehouseId
     * @param bool $forceUpdate 強制更新フラグ
     * @return WmsDailyStat
     */
    public function getOrUpdateDailyStats(Carbon $date, int $warehouseId, bool $forceUpdate = false): WmsDailyStat
    {
        $stat = WmsDailyStat::where('warehouse_id', $warehouseId)
            ->where('target_date', $date->format('Y-m-d'))
            ->first();

        // データが存在しない、または30分以上経過している、または強制更新の場合は再集計
        if (!$stat || $stat->isStale(30) || $forceUpdate) {
            return $this->calculate($date, $warehouseId);
        }

        return $stat;
    }

    /**
     * 指定日付・倉庫の統計データを集計して保存
     *
     * @param Carbon $date
     * @param int $warehouseId
     * @return WmsDailyStat
     */
    public function calculate(Carbon $date, int $warehouseId): WmsDailyStat
    {
        try {
            DB::connection('sakemaru')->beginTransaction();

            $dateStr = $date->format('Y-m-d');

            // 基本統計の集計
            $basicStats = $this->calculateBasicStats($dateStr, $warehouseId);

            // 欠品統計の集計
            $shortageStats = $this->calculateShortageStats($dateStr, $warehouseId);

            // 金額関連の集計
            $amountStats = $this->calculateAmountStats($dateStr, $warehouseId);

            // カテゴリ別内訳の集計
            $categoryBreakdown = $this->calculateCategoryBreakdown($dateStr, $warehouseId);

            // データを保存
            $stat = WmsDailyStat::updateOrCreate(
                [
                    'warehouse_id' => $warehouseId,
                    'target_date' => $dateStr,
                ],
                array_merge(
                    $basicStats,
                    $shortageStats,
                    $amountStats,
                    [
                        'category_breakdown' => $categoryBreakdown,
                        'last_calculated_at' => now(),
                    ]
                )
            );

            DB::connection('sakemaru')->commit();

            Log::info("WMS Daily Stats calculated", [
                'warehouse_id' => $warehouseId,
                'target_date' => $dateStr,
                'stats_id' => $stat->id,
            ]);

            return $stat;
        } catch (\Exception $e) {
            DB::connection('sakemaru')->rollBack();
            Log::error("Failed to calculate WMS Daily Stats", [
                'warehouse_id' => $warehouseId,
                'target_date' => $dateStr,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 基本統計（伝票数、商品数等）を集計
     *
     * @param string $dateStr
     * @param int $warehouseId
     * @return array
     */
    private function calculateBasicStats(string $dateStr, int $warehouseId): array
    {
        // ピッキング明細から集計
        // shipment_dateがtarget_dateと一致するピッキングタスクに紐づく明細を対象とする
        $pickingStats = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pt.shipment_date', $dateStr)
            ->selectRaw('
                COUNT(DISTINCT pir.trade_id) as slip_count,
                COUNT(pir.id) as item_count,
                COUNT(DISTINCT pir.item_id) as unique_item_count
            ')
            ->first();

        // 配送コース数
        $deliveryCourseCount = DB::connection('sakemaru')
            ->table('wms_picking_tasks')
            ->where('warehouse_id', $warehouseId)
            ->where('shipment_date', $dateStr)
            ->distinct('delivery_course_id')
            ->count('delivery_course_id');

        // 合計出荷数量（総バラ数）
        // trade_itemsのtotal_piece_quantityを集計
        $totalShipQty = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pt.shipment_date', $dateStr)
            ->sum('ti.total_piece_quantity');

        return [
            'picking_slip_count' => $pickingStats->slip_count ?? 0,
            'picking_item_count' => $pickingStats->item_count ?? 0,
            'unique_item_count' => $pickingStats->unique_item_count ?? 0,
            'delivery_course_count' => $deliveryCourseCount ?? 0,
            'total_ship_qty' => $totalShipQty ?? 0,
        ];
    }

    /**
     * 欠品統計を集計
     *
     * @param string $dateStr
     * @param int $warehouseId
     * @return array
     */
    private function calculateShortageStats(string $dateStr, int $warehouseId): array
    {
        // wms_shortagesテーブルから欠品データを集計
        // created_atの日付がtarget_dateと一致するものを対象
        $shortageStats = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->join('wms_picking_item_results as pir', 'ws.source_pick_result_id', '=', 'pir.id')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->whereDate('ws.created_at', $dateStr)
            ->selectRaw('
                COUNT(DISTINCT ws.item_id) as unique_count,
                SUM(ws.shortage_quantity) as total_count
            ')
            ->first();

        return [
            'stockout_unique_count' => $shortageStats->unique_count ?? 0,
            'stockout_total_count' => $shortageStats->total_count ?? 0,
        ];
    }

    /**
     * 金額関連統計を集計
     *
     * @param string $dateStr
     * @param int $warehouseId
     * @return array
     */
    private function calculateAmountStats(string $dateStr, int $warehouseId): array
    {
        // trade_itemsから金額を集計
        $amountStats = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pt.shipment_date', $dateStr)
            ->selectRaw('
                SUM(ti.tax_excluded_amount) as amount_ex,
                SUM(ti.tax_included_amount) as amount_in,
                SUM(CASE WHEN ti.is_container_included = 1 THEN ti.container_amount ELSE 0 END) as container_deposit
            ')
            ->first();

        // 欠品損失額を計算
        // 欠品数 × trade_itemsの単価
        $opportunityLoss = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->join('wms_picking_item_results as pir', 'ws.source_pick_result_id', '=', 'pir.id')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->whereDate('ws.created_at', $dateStr)
            ->selectRaw('SUM(ws.shortage_quantity * ti.price) as total_loss')
            ->value('total_loss');

        return [
            'total_amount_ex' => $amountStats->amount_ex ?? 0,
            'total_amount_in' => $amountStats->amount_in ?? 0,
            'total_container_deposit' => $amountStats->container_deposit ?? 0,
            'total_opportunity_loss' => $opportunityLoss ?? 0,
        ];
    }

    /**
     * カテゴリ別内訳を集計
     *
     * @param string $dateStr
     * @param int $warehouseId
     * @return array
     */
    private function calculateCategoryBreakdown(string $dateStr, int $warehouseId): array
    {
        // カテゴリ別の集計
        $categoryData = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('categories as c', 'i.category_id', '=', 'c.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->where('pt.shipment_date', $dateStr)
            ->groupBy('c.id', 'c.name')
            ->selectRaw('
                c.id as category_id,
                c.name as category_name,
                SUM(ti.total_piece_quantity) as ship_qty,
                SUM(ti.tax_excluded_amount) as amount_ex,
                SUM(ti.tax_included_amount) as amount_in,
                SUM(CASE WHEN ti.is_container_included = 1 THEN ti.container_amount ELSE 0 END) as container_deposit
            ')
            ->get();

        // カテゴリ別欠品損失額
        $categoryLosses = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->join('wms_picking_item_results as pir', 'ws.source_pick_result_id', '=', 'pir.id')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('trade_items as ti', 'pir.trade_item_id', '=', 'ti.id')
            ->join('items as i', 'ws.item_id', '=', 'i.id')
            ->leftJoin('categories as c', 'i.category_id', '=', 'c.id')
            ->where('pt.warehouse_id', $warehouseId)
            ->whereDate('ws.created_at', $dateStr)
            ->groupBy('c.id')
            ->selectRaw('
                c.id as category_id,
                SUM(ws.shortage_quantity * ti.price) as opportunity_loss
            ')
            ->pluck('opportunity_loss', 'category_id');

        // JSONフォーマットに変換
        $categories = [];
        foreach ($categoryData as $category) {
            $categoryId = $category->category_id ?? 'unknown';
            $categories[$categoryId] = [
                'name' => $category->category_name ?? 'その他',
                'ship_qty' => (int) $category->ship_qty,
                'amount_ex' => (float) $category->amount_ex,
                'amount_in' => (float) $category->amount_in,
                'container_deposit' => (float) $category->container_deposit,
                'opportunity_loss' => (float) ($categoryLosses[$categoryId] ?? 0),
            ];
        }

        return [
            'categories' => $categories,
        ];
    }

    /**
     * 複数倉庫の統計を一括で更新
     *
     * @param Carbon $date
     * @param array|null $warehouseIds nullの場合は全倉庫
     * @return int 更新した倉庫数
     */
    public function bulkCalculate(Carbon $date, ?array $warehouseIds = null): int
    {
        // 倉庫IDが指定されていない場合は、アクティブな全倉庫を対象
        if ($warehouseIds === null) {
            $warehouseIds = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
        }

        $successCount = 0;
        foreach ($warehouseIds as $warehouseId) {
            try {
                $this->calculate($date, $warehouseId);
                $successCount++;
            } catch (\Exception $e) {
                Log::error("Failed to calculate stats for warehouse", [
                    'warehouse_id' => $warehouseId,
                    'date' => $date->format('Y-m-d'),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $successCount;
    }
}
