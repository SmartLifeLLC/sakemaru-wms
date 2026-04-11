<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsIncomingImportError extends WmsModel
{
    protected $table = 'wms_incoming_import_errors';

    protected $fillable = [
        'received_file_id',
        'received_slip_id',
        'received_detail_id',
        'error_type',
        'error_code',
        'error_message',
        'raw_data',
        'item_code',
        'expected_price',
        'actual_price',
        'is_resolved',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'expected_price' => 'decimal:2',
        'actual_price' => 'decimal:2',
    ];

    public function receivedFile(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedFile::class, 'received_file_id');
    }

    public function receivedSlip(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedSlip::class, 'received_slip_id');
    }

    public function receivedDetail(): BelongsTo
    {
        return $this->belongsTo(WmsIncomingReceivedDetail::class, 'received_detail_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
