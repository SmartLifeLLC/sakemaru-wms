<?php

namespace App\Services\PickingList;

use App\Enums\QuantityType;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;

/**
 * 横持ち出荷ピッキングリスト データ生成サービス
 */
class ProxyShipmentPickingListService
{
    /**
     * 横持ち出荷ピッキングリストのデータを生成
     *
     * @param  int  $targetWarehouseId  横持ち出荷倉庫（ピッキング対象）
     * @param  string  $shipmentDate  出荷日
     * @param  int|null  $deliveryCourseId  配送コース（null=全コース）
     * @return array{header: array, items: array, summary: array}
     */
    /**
     * @return array{header: array, courses: array<array{header: array, items: array, summary: array}>, summary: array}
     */
    public function generateList(int $targetWarehouseId, string $shipmentDate, ?int $deliveryCourseId = null): array
    {
        $query = WmsShortageAllocation::with([
            'shortage.item.piece_jan_code_information',
            'shortage.trade.partner',
            'shortage.warehouse',
            'targetWarehouse',
            'sourceWarehouse',
            'deliveryCourse',
        ])
            ->where('target_warehouse_id', $targetWarehouseId)
            ->where('shipment_date', $shipmentDate)
            ->where('is_confirmed', true)
            ->where('is_finished', false)
            ->whereIn('status', [
                WmsShortageAllocation::STATUS_RESERVED,
                WmsShortageAllocation::STATUS_PICKING,
            ]);

        if ($deliveryCourseId) {
            $query->where('delivery_course_id', $deliveryCourseId);
        }

        $allocations = $query
            ->orderBy('delivery_course_id')
            ->orderBy('id')
            ->get();

        if ($allocations->isEmpty()) {
            return [
                'header' => [],
                'courses' => [],
                'items' => [],
                'summary' => [],
            ];
        }

        $targetWarehouse = $allocations->first()->targetWarehouse;

        $header = [
            'title' => '横持ち出荷ピッキングリスト',
            'warehouse_name' => $targetWarehouse ? "[{$targetWarehouse->code}] {$targetWarehouse->name}" : '',
            'shipment_date' => $shipmentDate,
        ];

        // 配送コース別にグループ化
        $grouped = $allocations->groupBy('delivery_course_id');
        $courses = [];
        $allItems = [];
        $totalQty = 0;
        $totalItems = 0;

        foreach ($grouped as $courseId => $courseAllocations) {
            $course = $courseAllocations->first()->deliveryCourse;
            $courseName = $course ? "[{$course->code}] {$course->name}" : "コースID: {$courseId}";

            $courseHeader = array_merge($header, [
                'course_name' => $courseName,
                'course_id' => $courseId,
            ]);

            $courseItems = [];
            $courseQty = 0;

            foreach ($courseAllocations as $allocation) {
                $shortage = $allocation->shortage;
                $item = $shortage?->item;
                $partner = $shortage?->trade?->partner;
                $sourceWarehouse = $allocation->sourceWarehouse;

                // 規格
                $packaging = $item?->packaging ?? '';

                // 数量区分
                $qtyTypeLabel = '';
                $qtyType = QuantityType::tryFrom($allocation->assign_qty_type);
                if ($qtyType) {
                    $qtyTypeLabel = $qtyType->name();
                }

                // JAN CD（piece_jan_code_informationから直接取得）
                $janCode = $item?->piece_jan_code_information?->search_string ?? '';

                // 候補ロケーション取得
                $locationCode = $this->getCandidateLocationCode($targetWarehouseId, $shortage?->item_id);

                $row = [
                    'allocation_id' => $allocation->id,
                    'course_name' => $courseName,
                    'course_id' => $courseId,
                    'source_warehouse' => $sourceWarehouse ? "[{$sourceWarehouse->code}] {$sourceWarehouse->name}" : '',
                    'item_code' => $item?->code ?? '',
                    'item_name' => $item?->name ?? '',
                    'jan_code' => $janCode,
                    'packaging' => $packaging,
                    'assign_qty' => $allocation->assign_qty,
                    'qty_type' => $qtyTypeLabel,
                    'customer_name' => $partner?->name ?? '',
                    'customer_code' => $partner?->code ?? '',
                    'location_code' => $locationCode,
                    'slip_number' => $shortage?->trade_id ?? '',
                ];

                $courseItems[] = $row;
                $allItems[] = $row;
                $courseQty += $allocation->assign_qty;
                $totalQty += $allocation->assign_qty;
                $totalItems++;
            }

            $courses[] = [
                'header' => $courseHeader,
                'items' => $courseItems,
                'summary' => [
                    'total_items' => count($courseItems),
                    'total_qty' => $courseQty,
                ],
            ];
        }

        $summary = [
            'total_items' => $totalItems,
            'total_qty' => $totalQty,
            'course_count' => $grouped->count(),
        ];

        return [
            'header' => $header,
            'courses' => $courses,
            'items' => $allItems,
            'summary' => $summary,
        ];
    }

    /**
     * 候補ロケーションの先頭コードを取得（FEFO/FIFO順）
     */
    private function getCandidateLocationCode(int $warehouseId, ?int $itemId): string
    {
        if (! $itemId) {
            return '';
        }

        $result = DB::connection('sakemaru')
            ->selectOne("
                SELECT
                    CONCAT_WS('-', l.code1, l.code2, l.code3) AS location_code
                FROM (
                    SELECT DISTINCT
                        v.location_id,
                        v.expiration_date,
                        v.created_at,
                        v.real_stock_id
                    FROM wms_v_stock_available v
                    WHERE v.warehouse_id = ?
                      AND v.item_id = ?
                      AND v.available_for_wms > 0
                ) x
                LEFT JOIN locations l ON l.id = x.location_id
                GROUP BY x.location_id, l.code1, l.code2, l.code3
                ORDER BY MIN(x.expiration_date) ASC, MIN(x.created_at) ASC, MIN(x.real_stock_id) ASC
                LIMIT 1
            ", [$warehouseId, $itemId]);

        return $result?->location_code ?? '';
    }
}
