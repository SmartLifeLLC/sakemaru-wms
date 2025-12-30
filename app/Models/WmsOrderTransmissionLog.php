<?php

namespace App\Models;

use App\Enums\AutoOrder\TransmissionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 送信ログ
 */
class WmsOrderTransmissionLog extends WmsModel
{
    protected $table = 'wms_order_transmission_logs';

    protected $fillable = [
        'batch_code',
        'wms_order_jx_document_id',
        'transmission_type',
        'action',
        'status',
        'request_data',
        'response_data',
        'error_code',
        'error_message',
        'executed_by',
    ];

    protected $casts = [
        'transmission_type' => TransmissionType::class,
        'request_data' => 'array',
        'response_data' => 'array',
    ];

    public function jxDocument(): BelongsTo
    {
        return $this->belongsTo(WmsOrderJxDocument::class, 'wms_order_jx_document_id');
    }
}
