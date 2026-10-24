<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class WmsDistributionRow extends WmsModel
{
    use SoftDeletes;

    protected $table = 'wms_distribution_rows';

    protected $fillable = [
        'client_id',
        'mode',
        'warehouse_id',
        'row_id',
        'sort_order',
        'business_key',
        'source',
        'source_key',
        'product_code',
        'item_id',
        'row_data',
        'created_by',
        'updated_by',
        'deleted_at',
    ];

    protected $casts = [
        'row_data' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForMode(Builder $query, string $mode): Builder
    {
        return $query->where('mode', $mode);
    }
}
