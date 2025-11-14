<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsWarehouseLayout extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'wms_warehouse_layouts';

    protected $fillable = [
        'warehouse_id',
        'floor_id',
        'width',
        'height',
        'colors',
        'text_styles',
        'walls',
        'fixed_areas',
        'picking_start_x',
        'picking_start_y',
        'picking_end_x',
        'picking_end_y',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'floor_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'colors' => 'array',
        'text_styles' => 'array',
        'walls' => 'array',
        'fixed_areas' => 'array',
        'picking_start_x' => 'integer',
        'picking_start_y' => 'integer',
        'picking_end_x' => 'integer',
        'picking_end_y' => 'integer',
    ];

    /**
     * Get the warehouse that owns this layout
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Warehouse::class, 'warehouse_id');
    }

    /**
     * Get the floor that owns this layout (optional)
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Floor::class, 'floor_id');
    }

    /**
     * Get default colors configuration
     */
    public static function getDefaultColors(): array
    {
        return [
            'location' => [
                'border' => '#D1D5DB',
                'rectangle' => '#E0F2FE',
            ],
            'wall' => [
                'border' => '#6B7280',
                'rectangle' => '#9CA3AF',
            ],
            'fixed_area' => [
                'border' => '#F59E0B',
                'rectangle' => '#FEF3C7',
            ],
        ];
    }

    /**
     * Get default text styles configuration
     */
    public static function getDefaultTextStyles(): array
    {
        return [
            'location' => [
                'color' => '#6B7280',
                'size' => 12,
            ],
            'wall' => [
                'color' => '#FFFFFF',
                'size' => 10,
            ],
            'fixed_area' => [
                'color' => '#92400E',
                'size' => 12,
            ],
        ];
    }
}
