<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmsPickingNavNode extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_nav_nodes';

    protected $fillable = [
        'warehouse_id',
        'floor_id',
        'x',
        'y',
        'kind',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'floor_id' => 'integer',
        'x' => 'integer',
        'y' => 'integer',
    ];

    /**
     * Get edges originating from this node
     */
    public function edgesFrom()
    {
        return $this->hasMany(WmsPickingNavEdge::class, 'node_u');
    }

    /**
     * Get edges ending at this node
     */
    public function edgesTo()
    {
        return $this->hasMany(WmsPickingNavEdge::class, 'node_v');
    }
}
