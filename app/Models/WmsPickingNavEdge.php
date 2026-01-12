<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmsPickingNavEdge extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_nav_edges';

    protected $fillable = [
        'warehouse_id',
        'floor_id',
        'node_u',
        'node_v',
        'length',
        'is_blocked',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'floor_id' => 'integer',
        'node_u' => 'integer',
        'node_v' => 'integer',
        'length' => 'integer',
        'is_blocked' => 'boolean',
    ];

    /**
     * Get the node this edge originates from
     */
    public function nodeFrom()
    {
        return $this->belongsTo(WmsPickingNavNode::class, 'node_u');
    }

    /**
     * Get the node this edge ends at
     */
    public function nodeTo()
    {
        return $this->belongsTo(WmsPickingNavNode::class, 'node_v');
    }
}
