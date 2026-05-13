<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Warehouse91StockLotSyncService
{
    private const WAREHOUSE_CODE = 91;

    private const CONNECTION = 'sakemaru';

    public function preview(): array
    {
        $rows = collect($this->loadPlan());

        return [
            'warehouse_code' => self::WAREHOUSE_CODE,
            'picking_checks' => $this->pickingChecks(),
            'summary' => $this->summarize($rows),
            'rows' => $rows,
            'create_lots' => $rows->where('action', 'create_lot')->values(),
        ];
    }

    public function sync(): array
    {
        $pickingChecks = $this->assertNoPickingInProgress();
        $rows = collect($this->loadPlan());
        $blockedRows = $rows->filter(fn (array $row): bool => $row['origin_status'] === 'ambiguous' || blank($row['target_location_id']));

        if ($blockedRows->isNotEmpty()) {
            $itemCodes = $blockedRows->pluck('item_code')->take(10)->implode(', ');
            throw new RuntimeException("origin棚番またはMySQLロケーションを確定できない商品があります。item_code={$itemCodes}");
        }

        DB::connection(self::CONNECTION)->transaction(function () use ($rows) {
            $now = now();

            foreach ($rows as $row) {
                if ($row['action'] === 'update_lot') {
                    $values = [
                        'current_quantity' => DB::raw('current_quantity + '.(int) $row['current_delta']),
                        'reserved_quantity' => DB::raw('reserved_quantity + '.(int) $row['reserved_delta']),
                        'updated_at' => $now,
                    ];

                    if ($row['retarget_lot']) {
                        $values['floor_id'] = $row['target_floor_id'];
                        $values['location_id'] = $row['target_location_id'];
                    }

                    $updated = DB::connection(self::CONNECTION)
                        ->table('real_stock_lots')
                        ->where('id', $row['target_lot_id'])
                        ->where('real_stock_id', $row['real_stock_id'])
                        ->where('status', 'ACTIVE')
                        ->update($values);

                    if ($updated !== 1) {
                        throw new RuntimeException("ロット更新に失敗しました。lot_id={$row['target_lot_id']}");
                    }

                    continue;
                }

                if ($row['action'] !== 'create_lot') {
                    throw new RuntimeException("未対応の同期操作です: {$row['action']}");
                }

                if (blank($row['target_location_id']) || blank($row['target_floor_id'])) {
                    throw new RuntimeException("新規ロット作成先の棚番がありません。item_code={$row['item_code']}");
                }

                DB::connection(self::CONNECTION)
                    ->table('real_stock_lots')
                    ->insert([
                        'real_stock_id' => $row['real_stock_id'],
                        'purchase_id' => null,
                        'trade_item_id' => null,
                        'purchase_price' => null,
                        'floor_id' => $row['target_floor_id'],
                        'location_id' => $row['target_location_id'],
                        'price' => null,
                        'content_amount' => 0,
                        'container_amount' => 0,
                        'expiration_date' => null,
                        'alert_date' => null,
                        'initial_quantity' => $row['real_stock_current_quantity'],
                        'current_quantity' => $row['real_stock_current_quantity'],
                        'reserved_quantity' => $row['real_stock_reserved_quantity'],
                        'status' => 'ACTIVE',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
        });

        $remaining = collect($this->loadPlan());

        return [
            'warehouse_code' => self::WAREHOUSE_CODE,
            'picking_checks' => $pickingChecks,
            'before' => $this->summarize($rows),
            'after_remaining' => $this->summarize($remaining),
        ];
    }

    private function loadPlan(): array
    {
        $sql = <<<'SQL'
WITH lot_ranked AS (
    SELECT
        rsl.*,
        ROW_NUMBER() OVER (
            PARTITION BY rsl.real_stock_id
            ORDER BY rsl.updated_at DESC, rsl.id DESC
        ) AS rn
    FROM real_stock_lots rsl
    WHERE rsl.status = 'ACTIVE'
),
lot_agg AS (
    SELECT
        real_stock_id,
        SUM(current_quantity) AS lot_current_quantity,
        SUM(reserved_quantity) AS lot_reserved_quantity,
        COUNT(*) AS active_lot_count
    FROM real_stock_lots
    WHERE status = 'ACTIVE'
    GROUP BY real_stock_id
),
origin_agg AS (
    SELECT
        item_id,
        COUNT(*) AS origin_rows,
        SUM(CASE WHEN NULLIF(oracle_shelf_code, '') IS NOT NULL THEN 1 ELSE 0 END) AS shelf_rows,
        COUNT(DISTINCT NULLIF(oracle_shelf_code, '')) AS distinct_shelf_count,
        GROUP_CONCAT(DISTINCT NULLIF(oracle_shelf_code, '') ORDER BY oracle_shelf_code SEPARATOR ',') AS oracle_shelves
    FROM wms_hana_origin_locations
    WHERE warehouse_id = 91
      AND item_id IS NOT NULL
    GROUP BY item_id
),
unique_shelf AS (
    SELECT item_id, MIN(oracle_shelf_code) AS shelf_code
    FROM wms_hana_origin_locations
    WHERE warehouse_id = 91
      AND item_id IS NOT NULL
      AND NULLIF(oracle_shelf_code, '') IS NOT NULL
    GROUP BY item_id
    HAVING COUNT(DISTINCT oracle_shelf_code) = 1
),
origin_target AS (
    SELECT
        rs.item_id,
        CASE
            WHEN oa.item_id IS NULL THEN 'origin_missing_z00'
            WHEN us.shelf_code IS NOT NULL THEN 'unique'
            WHEN COALESCE(oa.shelf_rows, 0) = 0 THEN 'origin_no_shelf_z00'
            ELSE 'ambiguous'
        END AS origin_status,
        CASE
            WHEN oa.item_id IS NULL THEN 'Z00'
            WHEN us.shelf_code IS NOT NULL THEN us.shelf_code
            WHEN COALESCE(oa.shelf_rows, 0) = 0 THEN 'Z00'
            ELSE NULL
        END AS target_shelf,
        oa.origin_rows,
        oa.oracle_shelves
    FROM real_stocks rs
    JOIN warehouses w ON w.id = rs.warehouse_id
    LEFT JOIN origin_agg oa ON oa.item_id = rs.item_id
    LEFT JOIN unique_shelf us ON us.item_id = rs.item_id
    WHERE w.code = 91
),
z00_locations AS (
    SELECT
        l.warehouse_id,
        l.id AS location_id,
        l.floor_id,
        CONCAT(COALESCE(l.code1, ''), COALESCE(l.code2, ''), COALESCE(l.code3, '')) AS location_code,
        l.name AS location_name
    FROM locations l
    WHERE CONCAT(COALESCE(l.code1, ''), COALESCE(l.code2, ''), COALESCE(l.code3, '')) = 'Z00'
)
SELECT
    rs.id AS real_stock_id,
    rs.warehouse_id,
    w.code AS warehouse_code,
    rs.stock_allocation_id,
    rs.item_id,
    i.code AS item_code,
    i.name AS item_name,
    rs.current_quantity AS real_stock_current_quantity,
    COALESCE(rs.reserved_quantity, 0) AS real_stock_reserved_quantity,
    COALESCE(la.lot_current_quantity, 0) AS lot_current_quantity,
    COALESCE(la.lot_reserved_quantity, 0) AS lot_reserved_quantity,
    COALESCE(la.active_lot_count, 0) AS active_lot_count,
    lr.id AS target_lot_id,
    lr.floor_id AS target_lot_floor_id,
    lr.location_id AS target_lot_location_id,
    lr.current_quantity AS target_lot_current_quantity,
    lr.reserved_quantity AS target_lot_reserved_quantity,
    COALESCE(
        NULLIF(lr_loc.name, ''),
        NULLIF(CONCAT(COALESCE(lr_loc.code1, ''), COALESCE(lr_loc.code2, ''), COALESCE(lr_loc.code3, '')), '')
    ) AS target_lot_location_label,
    NULLIF(CONCAT(COALESCE(lr_loc.code1, ''), COALESCE(lr_loc.code2, ''), COALESCE(lr_loc.code3, '')), '') AS target_lot_location_code,
    ot.origin_status,
    ot.target_shelf,
    ot.origin_rows,
    ot.oracle_shelves,
    CASE WHEN ot.target_shelf = 'Z00' THEN zl.location_id ELSE target_loc.id END AS target_location_id,
    CASE WHEN ot.target_shelf = 'Z00' THEN zl.floor_id ELSE target_loc.floor_id END AS target_floor_id,
    CASE
        WHEN ot.target_shelf = 'Z00' THEN COALESCE(zl.location_name, zl.location_code)
        ELSE COALESCE(
            NULLIF(target_loc.name, ''),
            NULLIF(CONCAT(COALESCE(target_loc.code1, ''), COALESCE(target_loc.code2, ''), COALESCE(target_loc.code3, '')), '')
        )
    END AS target_location_label,
    CASE
        WHEN ot.target_shelf = 'Z00' THEN zl.location_code
        ELSE NULLIF(CONCAT(COALESCE(target_loc.code1, ''), COALESCE(target_loc.code2, ''), COALESCE(target_loc.code3, '')), '')
    END AS target_location_code
FROM real_stocks rs
JOIN warehouses w ON w.id = rs.warehouse_id
JOIN items i ON i.id = rs.item_id
LEFT JOIN lot_agg la ON la.real_stock_id = rs.id
LEFT JOIN lot_ranked lr ON lr.real_stock_id = rs.id AND lr.rn = 1
LEFT JOIN locations lr_loc ON lr_loc.id = lr.location_id
LEFT JOIN origin_target ot ON ot.item_id = rs.item_id
LEFT JOIN locations target_loc
  ON target_loc.warehouse_id = rs.warehouse_id
 AND ot.target_shelf COLLATE utf8mb4_unicode_ci <> 'Z00'
 AND (
      target_loc.name COLLATE utf8mb4_unicode_ci = ot.target_shelf COLLATE utf8mb4_unicode_ci
      OR CONCAT(COALESCE(target_loc.code1, ''), COALESCE(target_loc.code2, ''), COALESCE(target_loc.code3, '')) COLLATE utf8mb4_unicode_ci = ot.target_shelf COLLATE utf8mb4_unicode_ci
 )
LEFT JOIN z00_locations zl ON zl.warehouse_id = rs.warehouse_id
WHERE w.code = ?
  AND (
    rs.current_quantity <> COALESCE(la.lot_current_quantity, 0)
    OR COALESCE(rs.reserved_quantity, 0) <> COALESCE(la.lot_reserved_quantity, 0)
  )
ORDER BY ABS(rs.current_quantity - COALESCE(la.lot_current_quantity, 0)) DESC,
         ABS(COALESCE(rs.reserved_quantity, 0) - COALESCE(la.lot_reserved_quantity, 0)) DESC,
         i.code,
         rs.id
SQL;

        return collect(DB::connection(self::CONNECTION)->select($sql, [self::WAREHOUSE_CODE]))
            ->map(function (object $row): array {
                $currentDelta = (int) $row->real_stock_current_quantity - (int) $row->lot_current_quantity;
                $reservedDelta = (int) $row->real_stock_reserved_quantity - (int) $row->lot_reserved_quantity;
                $action = $row->target_lot_id ? 'update_lot' : 'create_lot';
                $targetLocationId = $row->target_location_id ? (int) $row->target_location_id : null;
                $retargetLot = $row->target_lot_id
                    && $targetLocationId
                    && (int) $row->target_lot_location_id !== $targetLocationId;
                $targetLotCurrentAfter = $row->target_lot_id
                    ? (int) $row->target_lot_current_quantity + $currentDelta
                    : (int) $row->real_stock_current_quantity;
                $targetLotReservedAfter = $row->target_lot_id
                    ? (int) $row->target_lot_reserved_quantity + $reservedDelta
                    : (int) $row->real_stock_reserved_quantity;
                $currentLocationDisplay = $row->target_lot_location_code === 'Z00'
                    ? 'Z00'
                    : (string) $row->target_lot_location_label;

                return [
                    'real_stock_id' => (int) $row->real_stock_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'warehouse_code' => (int) $row->warehouse_code,
                    'stock_allocation_id' => (int) $row->stock_allocation_id,
                    'item_id' => (int) $row->item_id,
                    'item_code' => (string) $row->item_code,
                    'item_name' => (string) $row->item_name,
                    'real_stock_current_quantity' => (int) $row->real_stock_current_quantity,
                    'real_stock_reserved_quantity' => (int) $row->real_stock_reserved_quantity,
                    'lot_current_quantity' => (int) $row->lot_current_quantity,
                    'lot_reserved_quantity' => (int) $row->lot_reserved_quantity,
                    'active_lot_count' => (int) $row->active_lot_count,
                    'target_lot_id' => $row->target_lot_id ? (int) $row->target_lot_id : null,
                    'target_lot_floor_id' => $row->target_lot_floor_id ? (int) $row->target_lot_floor_id : null,
                    'target_lot_location_id' => $row->target_lot_location_id ? (int) $row->target_lot_location_id : null,
                    'target_lot_current_quantity' => $row->target_lot_current_quantity === null ? null : (int) $row->target_lot_current_quantity,
                    'target_lot_reserved_quantity' => $row->target_lot_reserved_quantity === null ? null : (int) $row->target_lot_reserved_quantity,
                    'target_lot_current_quantity_after' => $targetLotCurrentAfter,
                    'target_lot_reserved_quantity_after' => $targetLotReservedAfter,
                    'current_location_display' => $row->target_lot_id ? $currentLocationDisplay : '-',
                    'origin_status' => (string) $row->origin_status,
                    'origin_rows' => (int) ($row->origin_rows ?? 0),
                    'oracle_shelves' => (string) ($row->oracle_shelves ?? ''),
                    'target_shelf' => (string) $row->target_shelf,
                    'target_location_id' => $targetLocationId,
                    'target_floor_id' => $row->target_floor_id ? (int) $row->target_floor_id : null,
                    'target_location_code' => (string) $row->target_location_code,
                    'target_location_label' => (string) $row->target_location_label,
                    'target_location_display' => $row->target_location_code === 'Z00'
                        ? 'Z00'
                        : (string) $row->target_location_label,
                    'current_delta' => $currentDelta,
                    'reserved_delta' => $reservedDelta,
                    'current_abs_delta' => abs($currentDelta),
                    'reserved_abs_delta' => abs($reservedDelta),
                    'action' => $action,
                    'retarget_lot' => $retargetLot,
                ];
            })
            ->all();
    }

    private function summarize(Collection $rows): array
    {
        return [
            'rows' => $rows->count(),
            'update_lot' => $rows->where('action', 'update_lot')->count(),
            'create_lot' => $rows->where('action', 'create_lot')->count(),
            'retarget_lot' => $rows->where('retarget_lot', true)->count(),
            'blocked' => $rows->filter(fn (array $row): bool => $row['origin_status'] === 'ambiguous' || blank($row['target_location_id']))->count(),
            'current_delta_total' => $rows->sum('current_delta'),
            'reserved_delta_total' => $rows->sum('reserved_delta'),
            'current_abs_delta_total' => $rows->sum('current_abs_delta'),
            'reserved_abs_delta_total' => $rows->sum('reserved_abs_delta'),
        ];
    }

    private function assertNoPickingInProgress(): array
    {
        $checks = $this->pickingChecks();
        $blocking = array_filter($checks);

        if ($blocking !== []) {
            throw new RuntimeException('ピッキング中データがあるため在庫同期を停止しました。');
        }

        return $checks;
    }

    private function pickingChecks(): array
    {
        return [
            'earnings_picking' => (int) DB::connection(self::CONNECTION)
                ->table('earnings')
                ->where('picking_status', 'PICKING')
                ->count(),
            'wms_picking_tasks_picking' => (int) DB::connection(self::CONNECTION)
                ->table('wms_picking_tasks')
                ->where('status', 'PICKING')
                ->count(),
            'wms_picking_item_results_earning_picking' => (int) DB::connection(self::CONNECTION)
                ->table('wms_picking_item_results')
                ->where('source_type', 'EARNING')
                ->where('status', 'PICKING')
                ->count(),
        ];
    }
}
