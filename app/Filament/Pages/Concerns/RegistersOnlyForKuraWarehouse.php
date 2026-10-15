<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Sakemaru\Warehouse;

trait RegistersOnlyForKuraWarehouse
{
    private const KURA_WAREHOUSE_CODE = '91';

    /** @var array<int, bool> */
    private static array $kuraWarehouseNavigationCache = [];

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && static::isKuraWarehouseSelected();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation();
    }

    protected static function isKuraWarehouseSelected(): bool
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        if (! $warehouseId) {
            return false;
        }

        $warehouseId = (int) $warehouseId;

        if (! array_key_exists($warehouseId, self::$kuraWarehouseNavigationCache)) {
            self::$kuraWarehouseNavigationCache[$warehouseId] = Warehouse::query()
                ->whereKey($warehouseId)
                ->where('code', self::KURA_WAREHOUSE_CODE)
                ->exists();
        }

        return self::$kuraWarehouseNavigationCache[$warehouseId];
    }
}
