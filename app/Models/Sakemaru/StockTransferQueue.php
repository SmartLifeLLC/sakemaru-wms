<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferQueue extends SakemaruModel
{
    protected $table = 'stock_transfer_queue';

    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'process_date' => 'date',
        'delivered_date' => 'date',
        'is_success' => 'boolean',
        'next_retry_at' => 'datetime',
    ];

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_code', 'code');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_code', 'code');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'delivery_course_id');
    }

    protected function batchCode(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                preg_match('/バッチ:([0-9]+)/u', (string) $this->note, $matches);

                return $matches[1] ?? null;
            },
        );
    }

    protected function itemCount(): Attribute
    {
        return Attribute::make(
            get: fn (): int => count($this->items ?? []),
        );
    }
}
