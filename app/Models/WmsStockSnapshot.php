<?php

namespace App\Models;

use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsStockSnapshot extends WmsModel
{
    protected $table = 'wms_stock_snapshots';

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $fillable = [
        'snapshot_date',
        'snapshot_time',
        'warehouse_id',
        'item_id',
        'current_quantity',
        'reserved_quantity',
        'available_quantity',
        'incoming_quantity',
        'stock_count',
        'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'current_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'available_quantity' => 'integer',
        'incoming_quantity' => 'integer',
        'stock_count' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function getKey()
    {
        return self::compoundKey(
            $this->snapshot_date?->toDateString() ?? (string) $this->getAttribute('snapshot_date'),
            (string) $this->snapshot_time,
            (string) $this->warehouse_id,
            (string) $this->item_id,
        );
    }

    public static function compoundKey(string $snapshotDate, string $snapshotTime, string $warehouseId, string $itemId): string
    {
        return implode('|', array_map(rawurlencode(...), [
            $snapshotDate,
            $snapshotTime,
            $warehouseId,
            $itemId,
        ]));
    }

    /**
     * @return array{snapshot_date: string, snapshot_time: string, warehouse_id: string, item_id: string}|null
     */
    public static function parseCompoundKey(string $key): ?array
    {
        $parts = array_map(rawurldecode(...), explode('|', $key));

        if (count($parts) !== 4) {
            return null;
        }

        [$snapshotDate, $snapshotTime, $warehouseId, $itemId] = $parts;

        return [
            'snapshot_date' => $snapshotDate,
            'snapshot_time' => $snapshotTime,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('snapshot_date', $date);
    }

    public function scopeMorning(Builder $query): Builder
    {
        return $query->where('snapshot_time', 'morning');
    }

    public function scopeEvening(Builder $query): Builder
    {
        return $query->where('snapshot_time', 'evening');
    }
}
