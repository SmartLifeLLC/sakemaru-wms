<?php

namespace App\Services\LotAdjustment;

use Illuminate\Support\Facades\DB;

/**
 * C. ACTIVE LOT合計 vs real_stocks の不一致検出（検出のみ・適用しない）
 *
 * 本番調査（2026-06-27）で 91番倉庫の不一致は 0 件。正方向の同期は棚番事故リスクが高く、
 * main_shelf_number も不在のため自動決定できない。よって今回は検出・表示のみ。
 */
class LotParentSyncDetector
{
    private string $conn = 'sakemaru';

    /**
     * @return array<int, array<string, mixed>> 明細（type=SYNC_DETECTED）
     */
    public function detectForWarehouse(int $warehouseId): array
    {
        $rows = DB::connection($this->conn)
            ->table('real_stocks as rs')
            ->leftJoin('real_stock_lots as l', 'l.real_stock_id', '=', 'rs.id')
            ->where('rs.warehouse_id', $warehouseId)
            ->groupBy('rs.id', 'rs.current_quantity', 'rs.reserved_quantity')
            ->havingRaw('rs.current_quantity <> COALESCE(SUM(CASE WHEN l.status = ? THEN l.current_quantity END), 0)
                      OR rs.reserved_quantity <> COALESCE(SUM(CASE WHEN l.status = ? THEN l.reserved_quantity END), 0)', ['ACTIVE', 'ACTIVE'])
            ->selectRaw('rs.id as real_stock_id,
                rs.current_quantity as parent_current,
                rs.reserved_quantity as parent_reserved,
                COALESCE(SUM(CASE WHEN l.status = ? THEN l.current_quantity END), 0) as active_current,
                COALESCE(SUM(CASE WHEN l.status = ? THEN l.reserved_quantity END), 0) as active_reserved', ['ACTIVE', 'ACTIVE'])
            ->orderBy('rs.id')
            ->get();

        return $rows->map(fn ($row) => [
            'type' => 'SYNC_DETECTED',
            'real_stock_id' => (int) $row->real_stock_id,
            'lot_id' => null,
            'current_before' => (int) $row->active_current,
            'current_after' => (int) $row->parent_current,   // 同期後の目標（参考。適用はしない）
            'reserved_before' => (int) $row->active_reserved,
            'reserved_after' => (int) $row->parent_reserved,
            'reason' => 'PARENT_ACTIVE_MISMATCH (detect only)',
        ])->all();
    }
}
