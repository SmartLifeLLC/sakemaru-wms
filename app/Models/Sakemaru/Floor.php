<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Floor extends CustomModel
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [];

    /**
     * floors table doesn't have is_active column
     */
    protected bool $hasIsActiveColumn = false;

    protected static function booted(): void
    {
        static::creating(function (Floor $floor) {
            // client_idは廃止予定だが、一旦はwarehouseのclient_idまたは最初のclientのIDを使用
            if (empty($floor->client_id)) {
                if (!empty($floor->warehouse_id)) {
                    $warehouse = Warehouse::find($floor->warehouse_id);
                    if ($warehouse) {
                        $floor->client_id = $warehouse->client_id;
                    }
                }

                // warehouseが見つからない場合は最初のclientを使用
                if (empty($floor->client_id)) {
                    $firstClient = Client::first();
                    if ($firstClient) {
                        $floor->client_id = $firstClient->id;
                    }
                }
            }

            // creator_idとlast_updater_idを設定（現在は0を使用）
            if (empty($floor->creator_id)) {
                $floor->creator_id = 0;
            }
            if (empty($floor->last_updater_id)) {
                $floor->last_updater_id = 0;
            }
        });

        static::updating(function (Floor $floor) {
            // client_idは廃止予定だが、一旦はwarehouseのclient_idまたは最初のclientのIDを使用
            if (empty($floor->client_id)) {
                if (!empty($floor->warehouse_id)) {
                    $warehouse = Warehouse::find($floor->warehouse_id);
                    if ($warehouse) {
                        $floor->client_id = $warehouse->client_id;
                    }
                }

                // warehouseが見つからない場合は最初のclientを使用
                if (empty($floor->client_id)) {
                    $firstClient = Client::first();
                    if ($firstClient) {
                        $floor->client_id = $firstClient->id;
                    }
                }
            }

            // last_updater_idを設定（現在は0を使用）
            if (empty($floor->last_updater_id)) {
                $floor->last_updater_id = 0;
            }
        });
    }

    public function warehouse() : belongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
