<?php

namespace App\Models;

use App\Models\Sakemaru\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsLocationLevel extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_location_levels';

    protected $fillable = [
        'location_id',
        'level_number',
        'name',
        'available_quantity_flags',
    ];

    protected $casts = [
        'level_number' => 'integer',
        'available_quantity_flags' => 'integer',
    ];

    /**
     * Get the location that owns this level
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
