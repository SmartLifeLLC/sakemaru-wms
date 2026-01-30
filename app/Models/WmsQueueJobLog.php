<?php

namespace App\Models;

use App\Enums\AutoOrder\QueueJobLogLevel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsQueueJobLog extends WmsModel
{
    protected $table = 'wms_queue_job_logs';

    public $timestamps = false;

    protected $fillable = [
        'queue_job_id',
        'level',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'level' => QueueJobLogLevel::class,
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function queueJob(): BelongsTo
    {
        return $this->belongsTo(WmsQueueJob::class, 'queue_job_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (! $log->created_at) {
                $log->created_at = now();
            }
        });
    }
}
