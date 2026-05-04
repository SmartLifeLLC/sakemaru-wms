<?php

namespace App\Filament\Resources\WmsStockSnapshot\Pages;

use App\Filament\Resources\WmsStockSnapshotResource;
use App\Models\WmsStockSnapshot;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

class ListWmsStockSnapshots extends ListRecords
{
    protected static string $resource = WmsStockSnapshotResource::class;

    public function getTableRecordKey(Model|array $record): string
    {
        if (! $record instanceof WmsStockSnapshot) {
            return parent::getTableRecordKey($record);
        }

        return $record->getKey();
    }

    protected function resolveTableRecord(?string $key): Model|array|null
    {
        if ($key === null) {
            return null;
        }

        $parts = WmsStockSnapshot::parseCompoundKey($key);

        if ($parts === null) {
            return parent::resolveTableRecord($key);
        }

        return WmsStockSnapshot::query()
            ->where('snapshot_date', $parts['snapshot_date'])
            ->where('snapshot_time', $parts['snapshot_time'])
            ->where('warehouse_id', $parts['warehouse_id'])
            ->where('item_id', $parts['item_id'])
            ->first();
    }
}
