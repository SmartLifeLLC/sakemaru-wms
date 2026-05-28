<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WmsInventoryCount extends WmsModel
{
    const STATUS_DRAFT = 'draft';

    const STATUS_COUNTING = 'counting';

    const STATUS_CHECKED = 'checked';

    const STATUS_CONFIRMED = 'confirmed';

    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'count_no',
        'client_id',
        'warehouse_id',
        'warehouse_code',
        'warehouse_name',
        'count_date',
        'status',
        'current_count_round',
        'lock_mode',
        'snapshot_taken_at',
        'started_at',
        'confirmed_at',
        'confirmed_by',
        'stock_adjustment_request_id',
        'stock_adjustment_queue_id',
        'stock_adjustment_id',
        'stock_adjustment_created_at',
        'stock_adjustment_error_message',
        'inventory_adjustment_request_id',
        'inventory_adjustment_queue_id',
        'inventory_adjustment_id',
        'inventory_adjustment_created_at',
        'inventory_adjustment_error_message',
        'first_count_confirmed_at',
        'first_count_confirmed_by',
        'second_count_confirmed_at',
        'second_count_confirmed_by',
        'final_count_confirmed_at',
        'final_count_confirmed_by',
        'memo',
        'created_by',
    ];

    protected $casts = [
        'count_date' => 'date',
        'current_count_round' => 'integer',
        'lock_mode' => 'boolean',
        'snapshot_taken_at' => 'datetime',
        'started_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'stock_adjustment_created_at' => 'datetime',
        'inventory_adjustment_created_at' => 'datetime',
        'first_count_confirmed_at' => 'datetime',
        'second_count_confirmed_at' => 'datetime',
        'final_count_confirmed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WmsInventoryCountItem::class, 'inventory_count_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateCountNo(string $countDate): string
    {
        return 'IC-'.date('Ymd', strtotime($countDate)).'-'.Str::upper(Str::random(8));
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_DRAFT,
            self::STATUS_COUNTING,
            self::STATUS_CHECKED,
        ]);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => '下書き',
            self::STATUS_COUNTING => 'カウント中',
            self::STATUS_CHECKED => '差異確認済',
            self::STATUS_CONFIRMED => '確定済',
            self::STATUS_CANCELLED => '取消',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'gray',
            self::STATUS_COUNTING => 'info',
            self::STATUS_CHECKED => 'warning',
            self::STATUS_CONFIRMED => 'success',
            self::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }
}
