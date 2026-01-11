<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 配送コース別プリンター設定
 *
 * 配送コースごとに使用するプリンターを設定
 */
class ClientPrinterCourseSetting extends Model
{
    protected $connection = 'sakemaru';
    protected $table = 'client_printer_course_settings';

    protected $fillable = [
        'client_id',
        'warehouse_id',
        'delivery_course_id',
        'printer_driver_id',
        'is_active',
        'creator_id',
        'last_updater_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'delivery_course_id');
    }

    public function printerDriver(): BelongsTo
    {
        return $this->belongsTo(ClientPrinterDriver::class, 'printer_driver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'creator_id');
    }

    public function lastUpdater(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'last_updater_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    // Boot method to set creator/updater
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->creator_id = auth()->id();
                $model->last_updater_id = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->last_updater_id = auth()->id();
            }
        });
    }
}
