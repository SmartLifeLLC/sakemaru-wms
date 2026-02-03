<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmsLayoutDistanceCache extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_layout_distance_cache';

    protected $fillable = [
        'warehouse_id',
        'floor_id',
        'layout_hash',
        'from_key',
        'to_key',
        'meters',
        'path_json',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'floor_id' => 'integer',
        'meters' => 'integer',
        'path_json' => 'array',
    ];
}
