<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'polygon' => 'array',
    ];

    /**
     * このピッキングエリアに属するロケーション
     */
    public function wmsLocations(): HasMany
    {
        return $this->hasMany(WmsLocation::class, 'wms_picking_area_id');
    }
}
