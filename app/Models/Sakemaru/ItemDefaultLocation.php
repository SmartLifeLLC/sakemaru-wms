<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemDefaultLocation extends SakemaruModel
{
    protected $table = 'item_incoming_default_locations';

    protected $fillable = [
        'warehouse_id',
        'item_id',
        'default_location_id',
        'creator_id',
        'last_updater_id',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    /**
     * 商品・倉庫のデフォルトロケーションを取得
     */
    public static function getDefaultLocation(int $warehouseId, int $itemId): ?Location
    {
        $record = static::where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->first();

        return $record?->defaultLocation;
    }
}
