<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Floor extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    protected static function booted(): void
    {
        // client_idは廃止予定だが、一旦はwarehouseのclient_idまたは最初のclientのIDを使用
        static::creating(function (Floor $floor) {
            if (empty($floor->client_id)) {
                if (!empty($floor->warehouse_id)) {
                    $warehouse = Warehouse::find($floor->warehouse_id);
                    if ($warehouse) {
                        $floor->client_id = $warehouse->client_id;
                        return;
                    }
                }

                // warehouseが見つからない場合は最初のclientを使用
                $firstClient = Client::first();
                if ($firstClient) {
                    $floor->client_id = $firstClient->id;
                }
            }
        });

        static::updating(function (Floor $floor) {
            if (empty($floor->client_id)) {
                if (!empty($floor->warehouse_id)) {
                    $warehouse = Warehouse::find($floor->warehouse_id);
                    if ($warehouse) {
                        $floor->client_id = $warehouse->client_id;
                        return;
                    }
                }

                // warehouseが見つからない場合は最初のclientを使用
                $firstClient = Client::first();
                if ($firstClient) {
                    $floor->client_id = $firstClient->id;
                }
            }
        });
    }

    public function warehouse() : belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
