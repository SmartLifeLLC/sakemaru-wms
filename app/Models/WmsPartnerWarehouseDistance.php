<?php

namespace App\Models;

use App\Models\Sakemaru\Partner;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsPartnerWarehouseDistance extends WmsModel
{
    protected $table = 'wms_partner_warehouse_distances';

    protected $fillable = [
        'partner_id',
        'warehouse_id',
        'distance_km',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
