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
