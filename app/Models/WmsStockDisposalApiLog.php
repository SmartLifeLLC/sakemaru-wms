<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsStockDisposalApiLog extends WmsModel
{
    protected $table = 'wms_stock_disposal_api_logs';

    protected $fillable = [
        'picker_id',
        'picker_code',
        'picker_name',
        'request_id',
        'queue_id',
        'warehouse_code',
        'reason',
        'process_date',
        'disposal_date',
        'slip_number',
        'detail_count',
        'request_payload',
        'result_status',
        'error_message',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'process_date' => 'date',
        'disposal_date' => 'date',
        'request_payload' => 'array',
    ];

    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }
}
