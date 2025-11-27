<?php

namespace App\Models;

use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class WmsPickingArea extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_picking_areas';

    protected $fillable = [
        'warehouse_id',
        'floor_id',
        'code',
        'name',
        'color',
        'display_order',
        'is_active',
        'polygon',
        'available_quantity_flags',
        'temperature_type',
        'is_restricted_area',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'polygon' => 'array',
        'available_quantity_flags' => 'integer',
        'is_restricted_area' => 'boolean',
    ];

    /**
     * このピッキングエリアに属するWmsLocation
     */
    public function wmsLocations(): HasMany
    {
        return $this->hasMany(WmsLocation::class, 'wms_picking_area_id');
    }

    /**
     * このエリアに含まれるLocations（WmsLocation経由）
     */
    public function locations(): HasManyThrough
    {
        return $this->hasManyThrough(
            Location::class,
            WmsLocation::class,
            'wms_picking_area_id', // wms_locations.wms_picking_area_id
            'id',                   // locations.id
            'id',                   // wms_picking_areas.id
            'location_id'           // wms_locations.location_id
        );
    }

    /**
     * エリア設定をエリア内の全ロケーションに適用
     */
    public function applySettingsToLocations(): int
    {
        $locationIds = $this->wmsLocations()->pluck('location_id');

        if ($locationIds->isEmpty()) {
            return 0;
        }

        $updateData = [];

        if ($this->available_quantity_flags !== null) {
            $updateData['available_quantity_flags'] = $this->available_quantity_flags;
        }

        if ($this->temperature_type !== null) {
            $updateData['temperature_type'] = $this->temperature_type;
        }

        // is_restricted_area は常に更新
        $updateData['is_restricted_area'] = $this->is_restricted_area ?? false;

        if (empty($updateData)) {
            return 0;
        }

        return Location::whereIn('id', $locationIds)->update($updateData);
    }
}
