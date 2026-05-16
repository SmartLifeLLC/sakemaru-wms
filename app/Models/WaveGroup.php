<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WaveGroup extends WmsModel
{
    protected $table = 'wms_wave_groups';

    protected $fillable = [
        'group_no',
        'warehouse_id',
        'shipping_date',
        'generation_type',
        'target_document_types',
        'conditions',
        'generation_result',
        'picking_lists',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'regenerated_from_wave_group_id',
    ];

    protected $casts = [
        'shipping_date' => 'date',
        'target_document_types' => 'array',
        'conditions' => 'array',
        'generation_result' => 'array',
        'picking_lists' => 'array',
        'cancelled_at' => 'datetime',
    ];

    public function waves(): HasMany
    {
        return $this->hasMany(Wave::class, 'wave_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateGroupNo(string $shippingDate): string
    {
        return 'WG-'.date('Ymd', strtotime($shippingDate)).'-'.Str::upper(Str::random(8));
    }
}
