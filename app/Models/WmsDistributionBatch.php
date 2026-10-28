<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WmsDistributionBatch extends WmsModel
{
    use SoftDeletes;

    protected $table = 'wms_distribution_batches';

    protected $guarded = ['id'];

    protected $casts = ['shipping_date' => 'date:Y-m-d', 'is_legacy' => 'boolean', 'archived_at' => 'datetime', 'revision' => 'integer'];

    public function rows(): HasMany
    {
        return $this->hasMany(WmsDistributionRow::class, 'distribution_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
