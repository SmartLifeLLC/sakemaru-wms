<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class WmsDistributionRow extends WmsModel
{
    use SoftDeletes;

    protected $table = 'wms_distribution_rows';

    protected $fillable = [
        'distribution_batch_id',
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

    protected static function booted(): void
    {
        // Aggregate store views have no batch context; editing routes must establish it.
        static::addGlobalScope('distribution_batch', function (Builder $query): void {
            $batch = \App\Services\Distribution\DistributionBatchService::current();
            if ($batch) {
                $query->where('distribution_batch_id', $batch->id);
            }
        });
    }

    public function batch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WmsDistributionBatch::class, 'distribution_batch_id');
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForMode(Builder $query, string $mode): Builder
    {
        return $query->where('mode', $mode);
    }
}
