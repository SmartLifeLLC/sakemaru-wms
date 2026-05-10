<?php

namespace App\Models\Sakemaru;

use App\Enums\PrintType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockTransfer extends CustomModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [];

    protected PrintType $checklist_print_type = PrintType::STOCK_TRANSFER_CHECK;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function from_warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function to_warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'delivery_course_id');
    }

    public function transferQueue(): HasOne
    {
        return $this->hasOne(StockTransferQueue::class, 'stock_transfer_id');
    }
}
