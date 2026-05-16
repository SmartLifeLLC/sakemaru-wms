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
                $shortageLocationId = (int) $shortage?->warehouse_id === $targetWarehouseId
                    ? $shortage?->location_id
                    : null;
                $locationCode = $this->getCandidateLocationCode($targetWarehouseId, $shortage?->item_id, $shortageLocationId);

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
    private function getCandidateLocationCode(int $warehouseId, ?int $itemId, ?int $shortageLocationId = null): string
    {
        if (! $itemId) {
            return '';
        }

        $availableLocation = DB::connection('sakemaru')
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

        if (! empty($availableLocation?->location_code)) {
            return $availableLocation->location_code;
        }

        $lotLocation = DB::connection('sakemaru')
            ->selectOne("
                SELECT
                    CONCAT_WS('-', l.code1, l.code2, l.code3) AS location_code
                FROM real_stocks rs
                INNER JOIN real_stock_lots rsl ON rsl.real_stock_id = rs.id
                LEFT JOIN locations l ON l.id = rsl.location_id
                WHERE rs.warehouse_id = ?
                  AND rs.item_id = ?
                  AND rsl.status = 'ACTIVE'
                  AND rsl.current_quantity > 0
                  AND rsl.location_id IS NOT NULL
                ORDER BY
                    rsl.expiration_date IS NULL ASC,
                    rsl.expiration_date ASC,
                    rsl.created_at ASC,
                    rsl.real_stock_id ASC
                LIMIT 1
            ", [$warehouseId, $itemId]);

        if (! empty($lotLocation?->location_code)) {
            return $lotLocation->location_code;
        }

        $defaultLocation = DB::connection('sakemaru')
            ->selectOne("
                SELECT
                    CONCAT_WS('-', l.code1, l.code2, l.code3) AS location_code
                FROM item_incoming_default_locations idl
                LEFT JOIN locations l ON l.id = idl.location_id
                WHERE idl.warehouse_id = ?
                  AND idl.item_id = ?
                LIMIT 1
            ", [$warehouseId, $itemId]);

        if (! empty($defaultLocation?->location_code)) {
            return $defaultLocation->location_code;
        }

        if ($shortageLocationId) {
            $shortageLocation = DB::connection('sakemaru')
                ->selectOne("
                    SELECT
                        CONCAT_WS('-', l.code1, l.code2, l.code3) AS location_code
                    FROM locations l
                    WHERE l.id = ?
                      AND l.warehouse_id = ?
                    LIMIT 1
                ", [$shortageLocationId, $warehouseId]);

            if (! empty($shortageLocation?->location_code)) {
                return $shortageLocation->location_code;
            }
        }

        if ($warehouseId === 91) {
            $originLocation = DB::connection('sakemaru')
                ->table('wms_hana_origin_locations')
                ->where('warehouse_id', $warehouseId)
                ->where('item_id', $itemId)
                ->whereNotNull('oracle_shelf_code')
                ->where('oracle_shelf_code', '!=', '')
                ->orderByDesc('last_purchase_date')
                ->orderByDesc('oracle_updated_at')
                ->value('oracle_shelf_code');

            if (! empty($originLocation)) {
                return (string) $originLocation;
            }
        }

        $fallbackLocation = DB::connection('sakemaru')
            ->selectOne("
                SELECT
                    CONCAT_WS('-', l.code1, l.code2, l.code3) AS location_code
                FROM locations l
                WHERE l.warehouse_id = ?
                  AND l.code1 = 'Z'
                  AND l.code2 = '0'
                  AND l.code3 = '0'
                LIMIT 1
            ", [$warehouseId]);

        if (! empty($fallbackLocation?->location_code)) {
            return $fallbackLocation->location_code;
        }

        return '';
    }
}
