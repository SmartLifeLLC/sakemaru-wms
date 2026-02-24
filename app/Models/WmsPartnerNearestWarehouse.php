<?php

namespace App\Models;

use App\Models\Sakemaru\Partner;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsPartnerNearestWarehouse extends WmsModel
{
    protected $table = 'wms_partner_nearest_warehouses';

    protected $fillable = [
        'partner_id',
        'nearest_warehouse_id',
        'min_distance_km',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'min_distance_km' => 'decimal:2',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function nearestWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'nearest_warehouse_id');
    }
}
