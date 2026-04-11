<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 仮想倉庫を実倉庫に解決するユーティリティ
 *
 * COALESCE(stock_warehouse_id, id) パターンを集約し、
 * 仮想倉庫の実倉庫解決を一元管理する。
 */
class WarehouseResolver
{
    /**
     * 仮想倉庫IDを実倉庫IDに解決する
     *
     * warehouses.stock_warehouse_id が設定されている場合はそれを返し、
     * 未設定（= 実倉庫）の場合はそのままのIDを返す。
     */
    public static function resolveRealWarehouseId(int $warehouseId): int
    {
        $stockWarehouseId = DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $warehouseId)
            ->value('stock_warehouse_id');

        return $stockWarehouseId ?? $warehouseId;
    }

    /**
     * 2つの倉庫IDが同一の実倉庫に紐づくか判定する
     */
    public static function isSameRealWarehouse(int $wh1, int $wh2): bool
    {
        return self::resolveRealWarehouseId($wh1) === self::resolveRealWarehouseId($wh2);
    }

    /**
     * 指定倉庫と同一実倉庫に属する全倉庫IDを取得
     *
     * 例: resolveAllWarehouseIds(91) → [91, 92, 93]
     *     (91=実倉庫, 92,93=91をstock_warehouse_idとして持つ仮想倉庫)
     */
    public static function resolveAllWarehouseIds(int $warehouseId): array
    {
        $realId = self::resolveRealWarehouseId($warehouseId);

        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where(function ($q) use ($realId) {
                $q->where('id', $realId)
                    ->orWhere('stock_warehouse_id', $realId);
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * 倉庫IDから実倉庫のコードを取得する
     */
    public static function getRealWarehouseCode(int $warehouseId): ?string
    {
        $realId = self::resolveRealWarehouseId($warehouseId);

        return DB::connection('sakemaru')
            ->table('warehouses')
            ->where('id', $realId)
            ->value('code');
    }
}
