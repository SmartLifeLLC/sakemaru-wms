<?php

namespace App\Models;

use App\Models\Sakemaru\Buyer;
use App\Models\Sakemaru\DeliveryCourse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WmsBuyerDeliveryCourseSwitchSetting extends WmsModel
{
    use SoftDeletes;

    protected $table = 'wms_buyer_delivery_course_switch_settings';

    protected $fillable = [
        'buyer_id',
        'switch_time',
        'to_delivery_course_id',
        'last_executed_date',
        'last_executed_at',
    ];

    protected $casts = [
        'last_executed_date' => 'date',
        'last_executed_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function toDeliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'to_delivery_course_id');
    }

    /**
     * switch_time のバリデーションルール: 15分単位のみ許可
     */
    public static function switchTimeRule(): string
    {
        return 'regex:/^([01]\d|2[0-3]):(00|15|30|45)(:00)?$/';
    }
}
