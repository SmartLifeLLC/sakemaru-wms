<?php

namespace App\Models\Sakemaru;

use App\Models\Sakemaru\ClientCalendar;

use App\Models\Sakemaru\WarehouseContractor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    protected static function booted(): void
    {
        // client_idは廃止予定だが、一旦は最初のclientのIDを使用
        static::creating(function (Warehouse $warehouse) {
            if (empty($warehouse->client_id)) {
                $firstClient = Client::first();
                if ($firstClient) {
                    $warehouse->client_id = $firstClient->id;
                }
            }
        });

        static::updating(function (Warehouse $warehouse) {
            if (empty($warehouse->client_id)) {
                $firstClient = Client::first();
                if ($firstClient) {
                    $warehouse->client_id = $firstClient->id;
                }
            }
        });
    }

    public function client_calendar(): BelongsTo
    {
        return $this->belongsTo(ClientCalendar::class);
    }

    public function warehouse_contractors() : HasMany
    {
        return $this->hasMany(WarehouseContractor::class);
    }
}
