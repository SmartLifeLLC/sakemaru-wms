<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wave extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_waves';

    protected $fillable = [
        'wms_wave_setting_id',
        'wave_no',
        'shipping_date',
        'status',
    ];

    protected $casts = [
        'shipping_date' => 'date',
    ];

    public function waveSetting(): BelongsTo
    {
        return $this->belongsTo(WaveSetting::class, 'wms_wave_setting_id');
    }

    public function pickingTasks(): HasMany
    {
        return $this->hasMany(WmsPickingTask::class, 'wave_id');
    }

    public function shortages(): HasMany
    {
        return $this->hasMany(WmsShortage::class, 'wave_id');
    }

    /**
     * Generate wave number in format: W###-C###-YYYYMMDD-{id}
     */
    public static function generateWaveNo(int $warehouseCode, int $courseCode, string $date, int $waveId): string
    {
        $dateFormatted = date('Ymd', strtotime($date));
        return sprintf('W%03d-C%03d-%s-%d', $warehouseCode, $courseCode, $dateFormatted, $waveId);
    }

    /**
     * Get wave code attribute (same as wave_no)
     */
    public function getWaveCodeAttribute(): string
    {
        return $this->wave_no ?? "Wave-{$this->id}";
    }
}
