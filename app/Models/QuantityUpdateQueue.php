<?php

namespace App\Models;

use App\Models\Sakemaru\Client;
use App\Models\Sakemaru\Trade;
use App\Models\Sakemaru\TradeItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuantityUpdateQueue extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'quantity_update_queue';

    protected $fillable = [
        'client_id',
        'trade_category',
        'trade_id',
        'trade_item_id',
        'update_qty',
        'quantity_type',
        'shipment_date',
        'request_id',
        'status',
        'is_success',
        'error_message',
    ];

    protected $casts = [
        'update_qty' => 'decimal:2',
        'shipment_date' => 'date',
        'is_success' => 'boolean',
    ];

    // Status constants
    public const STATUS_BEFORE = 'BEFORE';

    public const STATUS_UPDATING = 'UPDATING';

    public const STATUS_FINISHED = 'FINISHED';

    // Trade category constants
    public const TRADE_CATEGORY_EARNING = 'EARNING';

    public const TRADE_CATEGORY_PURCHASE = 'PURCHASE';

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function tradeItem(): BelongsTo
    {
        return $this->belongsTo(TradeItem::class, 'trade_item_id');
    }

    // Scopes
    public function scopeBefore($query)
    {
        return $query->where('status', self::STATUS_BEFORE);
    }

    public function scopeUpdating($query)
    {
        return $query->where('status', self::STATUS_UPDATING);
    }

    public function scopeFinished($query)
    {
        return $query->where('status', self::STATUS_FINISHED);
    }

    public function scopeSuccess($query)
    {
        return $query->where('is_success', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('is_success', false);
    }

    // Helper methods
    public function isBefore(): bool
    {
        return $this->status === self::STATUS_BEFORE;
    }

    public function isUpdating(): bool
    {
        return $this->status === self::STATUS_UPDATING;
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function isSuccess(): bool
    {
        return $this->is_success === true;
    }

    public function isFailed(): bool
    {
        return $this->is_success === false;
    }
}
