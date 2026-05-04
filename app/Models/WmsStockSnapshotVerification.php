<?php

namespace App\Models;

class WmsStockSnapshotVerification extends WmsModel
{
    protected $table = 'wms_stock_snapshot_verifications';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_date',
        'snapshot_time',
        'summary_rows',
        'lot_rows',
        'summary_lot_mismatches',
        'realtime_mismatches',
        'realtime_total_diff',
        'row_count_ratio',
        'is_healthy',
        'details',
        'captured_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'summary_rows' => 'integer',
        'lot_rows' => 'integer',
        'summary_lot_mismatches' => 'integer',
        'realtime_mismatches' => 'integer',
        'realtime_total_diff' => 'integer',
        'row_count_ratio' => 'decimal:2',
        'is_healthy' => 'boolean',
        'details' => 'array',
        'captured_at' => 'datetime',
    ];
}
