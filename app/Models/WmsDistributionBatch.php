<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WmsDistributionBatch extends WmsModel
{
    use SoftDeletes;

    public const RESPONSIBLE_DEPARTMENTS = ['warehouse' => '倉庫担当', 'headquarters' => '本部担当'];

    public function responsibleDepartmentLabel(): string
    {
        return self::RESPONSIBLE_DEPARTMENTS[$this->responsible_department] ?? '未設定';
    }

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
