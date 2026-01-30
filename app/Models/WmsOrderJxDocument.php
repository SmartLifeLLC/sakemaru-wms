<?php

namespace App\Models;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionDocumentType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * JX発注ドキュメント
 */
class WmsOrderJxDocument extends WmsModel
{
    protected $table = 'wms_order_jx_documents';

    protected $fillable = [
        'batch_code',
        'wms_order_jx_setting_id',
        'warehouse_id',
        'contractor_id',
        'order_date',
        'expected_arrival_date',
        'document_type',
        'jx_document_no',
        'jx_message_id',
        'status',
        'file_path',
        'csv_path',
        'file_size',
        'record_count',
        'order_count',
        'encoding',
        'total_items',
        'total_quantity',
        'jx_request_data',
        'jx_response_data',
        'error_message',
        'transmitted_at',
        'confirmed_at',
        'transmitted_by',
    ];

    protected $casts = [
        'document_type' => TransmissionDocumentType::class,
        'status' => TransmissionDocumentStatus::class,
        'order_date' => 'date',
        'expected_arrival_date' => 'date',
        'jx_request_data' => 'array',
        'jx_response_data' => 'array',
        'transmitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function jxSetting(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxSetting::class, 'wms_order_jx_setting_id');
    }

    public function orderCandidates(): HasMany
    {
        return $this->hasMany(WmsOrderCandidate::class);
    }

    public function transmissionLogs(): HasMany
    {
        return $this->hasMany(WmsOrderTransmissionLog::class);
    }

    public function scopeForBatch(Builder $query, string $batchCode): Builder
    {
        return $query->where('batch_code', $batchCode);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransmissionDocumentStatus::PENDING);
    }

    public function scopeTransmitted(Builder $query): Builder
    {
        return $query->where('status', TransmissionDocumentStatus::TRANSMITTED);
    }
}
