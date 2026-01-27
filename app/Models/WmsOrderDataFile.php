<?php

namespace App\Models;

use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注データファイル（共通CSVダウンロード用）
 *
 * 倉庫別×発注先別に生成されるCSVファイルを管理
 */
class WmsOrderDataFile extends WmsModel
{
    protected $table = 'wms_order_data_files';

    protected $fillable = [
        'batch_code',
        'warehouse_id',
        'contractor_id',
        'order_date',
        'expected_arrival_date',
        'file_path',
        'file_size',
        'order_count',
        'total_quantity',
        'status',
        'downloaded_at',
        'downloaded_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'downloaded_at' => 'datetime',
        'status' => OrderDataFileStatus::class,
    ];

    // Relationships

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function downloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }

    // Scopes

    public function scopeForBatch(Builder $query, string $batchCode): Builder
    {
        return $query->where('batch_code', $batchCode);
    }

    public function scopeForWarehouse(Builder $query, int $warehouseId): Builder
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    // Methods

    /**
     * ダウンロード済みとしてマーク
     */
    public function markAsDownloaded(int $userId): void
    {
        $this->update([
            'status' => OrderDataFileStatus::DOWNLOADED,
            'downloaded_at' => now(),
            'downloaded_by' => $userId,
        ]);
    }
}
