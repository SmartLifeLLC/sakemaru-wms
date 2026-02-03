<?php

namespace App\Models;

use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * このエリアに含まれるロケーション
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'wms_picking_area_id');
    }

    /**
     * エリア設定をエリア内の全ロケーションに適用
     */
    public function applySettingsToLocations(): int
    {
        $count = $this->locations()->count();

        if ($count === 0) {
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

        $this->locations()->update($updateData);

        return $count;
    }

    /**
     * このエリアを担当できるピッカー
     */
    public function pickers(): BelongsToMany
    {
        return $this->belongsToMany(
            WmsPicker::class,
            'wms_picking_area_pickers',
            'wms_picking_area_id',
            'wms_picker_id'
        )->withTimestamps();
    }
}
