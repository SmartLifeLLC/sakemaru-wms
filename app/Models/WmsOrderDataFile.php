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
        'fax_file_path',
        'fax_downloaded_at',
        'fax_downloaded_by',
        'mail_to',
        'mail_sent_at',
        'mail_sent_by',
        'order_count',
        'total_quantity',
        'status',
        'is_test',
        'csv_downloaded_at',
        'csv_downloaded_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'csv_downloaded_at' => 'datetime',
        'fax_downloaded_at' => 'datetime',
        'mail_sent_at' => 'datetime',
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

    public function csvDownloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'csv_downloaded_by');
    }

    public function faxDownloadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fax_downloaded_by');
    }

    public function mailSentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mail_sent_by');
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
     * CSVダウンロード済みとしてマーク
     */
    public function markAsCsvDownloaded(int $userId): void
    {
        $this->update([
            'status' => OrderDataFileStatus::DOWNLOADED,
            'csv_downloaded_at' => now(),
            'csv_downloaded_by' => $userId,
        ]);
    }

    /**
     * FAXダウンロード済みとしてマーク
     */
    public function markAsFaxDownloaded(int $userId): void
    {
        $this->update([
            'status' => OrderDataFileStatus::DOWNLOADED,
            'fax_downloaded_at' => now(),
            'fax_downloaded_by' => $userId,
        ]);
    }

    /**
     * メール送信済みとしてマーク
     */
    public function markAsMailSent(int $userId, ?string $mailTo = null): void
    {
        $data = [
            'mail_sent_at' => now(),
            'mail_sent_by' => $userId,
        ];

        if ($mailTo !== null) {
            $data['mail_to'] = $mailTo;
        }

        $this->update($data);
    }

    /**
     * 後方互換性のためのエイリアス
     *
     * @deprecated Use csvDownloadedByUser() instead
     */
    public function downloadedByUser(): BelongsTo
    {
        return $this->csvDownloadedByUser();
    }

    /**
     * 後方互換性のためのエイリアス
     *
     * @deprecated Use markAsCsvDownloaded() instead
     */
    public function markAsDownloaded(int $userId): void
    {
        $this->markAsCsvDownloaded($userId);
    }
}
