<?php

namespace App\Services\Shortage;

use App\Enums\EItemSearchCodeType;
use App\Enums\EVolumeUnit;
use App\Enums\TemperatureType;
use App\Models\WmsShortageAllocation;
use Illuminate\Support\Facades\DB;

class ProxyShipmentQueryService
{
    /**
     * 横持ち出荷一覧を倉庫別に取得
     */
    public function listForWarehouse(int $warehouseId, ?string $shipmentDate, ?int $deliveryCourseId): array
    {
        $query = WmsShortageAllocation::with([
            'shortage.item.item_search_information',
            'shortage.trade.partner',
            'targetWarehouse',
            'sourceWarehouse',
            'deliveryCourse',
        ])
            ->readyForProxyPicking()
            ->where('target_warehouse_id', $warehouseId);

        if ($shipmentDate) {
            $query->where('shipment_date', $shipmentDate);
        }

        if ($deliveryCourseId) {
            $query->where('delivery_course_id', $deliveryCourseId);
        }

        $allocations = $query
            ->orderBy('shipment_date', 'asc')
            ->orderBy('delivery_course_id', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $data = $allocations->map(fn ($a) => $this->formatAllocation($a))->values()->toArray();

        // Build summary
        $byDeliveryCourse = $allocations->groupBy('delivery_course_id')
            ->map(function ($group) {
                $first = $group->first();
                $course = $first->deliveryCourse;

                return [
                    'id' => $first->delivery_course_id,
                    'code' => $course?->code,
                    'name' => $course?->name,
                    'count' => $group->count(),
                ];
            })
            ->values()
            ->toArray();

        return [
            'items' => $data,
            'summary' => [
                'total_count' => count($data),
                'by_delivery_course' => $byDeliveryCourse,
            ],
            'meta' => [
                'business_date' => now()->format('Y-m-d'),
            ],
        ];
    }

    /**
     * 横持ち出荷詳細を取得（倉庫一致検証含む）
     */
    public function findForWarehouse(int $allocationId, int $warehouseId): WmsShortageAllocation
    {
        $allocation = WmsShortageAllocation::with([
            'shortage.item.item_search_information',
            'shortage.trade.partner',
            'targetWarehouse',
            'sourceWarehouse',
            'deliveryCourse',
        ])->findOrFail($allocationId);

        if ((int) $allocation->target_warehouse_id !== $warehouseId) {
            throw new \InvalidArgumentException('指定された倉庫と一致しません');
        }

        return $allocation;
    }

    /**
     * 候補ロケーション取得（FEFO/FIFO順）
     */
    public function getCandidateLocations(WmsShortageAllocation $allocation): array
    {
        $shortage = $allocation->shortage;
        if (! $shortage) {
            return [];
        }

        $pickupWarehouseId = $allocation->target_warehouse_id;
        $itemId = $shortage->item_id;

        $results = DB::connection('sakemaru')
            ->select("
                SELECT
                    x.location_id,
                    CONCAT_WS('-', l.code1, l.code2, l.code3) AS location_code,
                    SUM(x.available_for_wms) AS available_qty
                FROM (
                    SELECT DISTINCT
                        v.real_stock_id,
                        v.location_id,
                        v.available_for_wms,
                        v.expiration_date,
                        v.created_at
                    FROM wms_v_stock_available v
                    WHERE v.warehouse_id = ?
                      AND v.item_id = ?
                      AND v.available_for_wms > 0
                ) x
                LEFT JOIN locations l ON l.id = x.location_id
                GROUP BY x.location_id, l.code1, l.code2, l.code3
                ORDER BY MIN(x.expiration_date) ASC, MIN(x.created_at) ASC, MIN(x.real_stock_id) ASC
            ", [$pickupWarehouseId, $itemId]);

        return array_map(fn ($row) => [
            'location_id' => $row->location_id,
            'code' => $row->location_code,
            'available_qty' => (int) $row->available_qty,
        ], $results);
    }

    /**
     * allocation をAPIレスポンス形式に整形
     */
    public function formatAllocation(WmsShortageAllocation $allocation): array
    {
        $shortage = $allocation->shortage;
        $item = $shortage?->item;
        $partner = $shortage?->trade?->partner;
        $targetWarehouse = $allocation->targetWarehouse;
        $sourceWarehouse = $allocation->sourceWarehouse;
        $deliveryCourse = $allocation->deliveryCourse;

        // JAN codes
        $janCodes = [];
        if ($item && $item->item_search_information) {
            $janCodes = $item->item_search_information
                ->filter(fn ($info) => $info->code_type === EItemSearchCodeType::JAN->value)
                ->sortByDesc('updated_at')
                ->pluck('search_string')
                ->values()
                ->toArray();
        }

        // Volume display
        $volumeDisplay = null;
        if ($item && $item->volume) {
            $volumeUnit = EVolumeUnit::tryFrom($item->volume_unit);
            $volumeDisplay = $item->volume . ($volumeUnit ? $volumeUnit->name() : '');
        }

        // Temperature type
        $temperatureTypeLabel = null;
        if ($item && $item->temperature_type) {
            $tempType = TemperatureType::tryFrom($item->temperature_type);
            $temperatureTypeLabel = $tempType?->label();
        }

        // Images
        $images = [];
        if ($item) {
            foreach (['image_url_1', 'image_url_2', 'image_url_3'] as $field) {
                if ($item->$field) {
                    $images[] = $item->$field;
                }
            }
        }

        return [
            'allocation_id' => $allocation->id,
            'shortage_id' => $allocation->shortage_id,
            'shipment_date' => $allocation->shipment_date?->format('Y-m-d'),
            'status' => $allocation->status,
            'pickup_warehouse' => $targetWarehouse ? [
                'id' => $targetWarehouse->id,
                'code' => $targetWarehouse->code,
                'name' => $targetWarehouse->name,
            ] : null,
            'destination_warehouse' => $sourceWarehouse ? [
                'id' => $sourceWarehouse->id,
                'code' => $sourceWarehouse->code,
                'name' => $sourceWarehouse->name,
            ] : null,
            'delivery_course' => $deliveryCourse ? [
                'id' => $deliveryCourse->id,
                'code' => $deliveryCourse->code,
                'name' => $deliveryCourse->name,
            ] : null,
            'item' => $item ? [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'jan_codes' => $janCodes,
                'volume' => $volumeDisplay,
                'capacity_case' => $item->capacity_case ?? null,
                'temperature_type' => $temperatureTypeLabel,
                'images' => $images,
            ] : null,
            'assign_qty' => $allocation->assign_qty,
            'assign_qty_type' => $allocation->assign_qty_type,
            'picked_qty' => $allocation->picked_qty ?? 0,
            'remaining_qty' => $allocation->remaining_qty,
            'customer' => $partner ? [
                'code' => $partner->code,
                'name' => $partner->name,
            ] : null,
            'slip_number' => $shortage?->trade_id,
            'is_editable' => ! $allocation->isFinished(),
        ];
    }

    /**
     * 詳細レスポンスを組み立て（候補ロケーション + shortage詳細付き）
     */
    public function formatDetailResponse(WmsShortageAllocation $allocation): array
    {
        $base = $this->formatAllocation($allocation);
        $shortage = $allocation->shortage;

        $base['shortage_detail'] = $shortage ? [
            'order_qty' => $shortage->order_qty,
            'planned_qty' => $shortage->planned_qty,
            'picked_qty' => $shortage->picked_qty,
            'shortage_qty' => $shortage->shortage_qty,
            'qty_type_at_order' => $shortage->qty_type_at_order,
        ] : null;

        $base['candidate_locations'] = $this->getCandidateLocations($allocation);

        return $base;
    }
}
