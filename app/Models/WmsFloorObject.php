<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsFloorObject extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_floor_objects';

    protected $fillable = [
        'floor_id',
        'type',
        'name',
        'description',
        'x1_pos',
        'y1_pos',
        'x2_pos',
        'y2_pos',
    ];

    protected $casts = [
        'floor_id' => 'integer',
        'x1_pos' => 'integer',
        'y1_pos' => 'integer',
        'x2_pos' => 'integer',
        'y2_pos' => 'integer',
    ];

    /**
     * Get the floor that owns this object
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Floor::class, 'floor_id');
    }
}
