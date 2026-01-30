<?php

namespace App\Models;

use App\Enums\AutoOrder\QueueJobStatus;
use App\Enums\AutoOrder\QueueJobType;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WmsQueueJob extends WmsModel
{
    protected $table = 'wms_queue_jobs';

    protected $fillable = [
        'job_type',
        'payload',
        'status',
        'priority',
        'attempts',
        'max_attempts',
        'source_system',
        'source_user_id',
        'source_reference_type',
        'source_reference_id',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'job_type' => QueueJobType::class,
        'status' => QueueJobStatus::class,
        'payload' => 'array',
        'result' => 'array',
        'priority' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'source_user_id' => 'integer',
        'source_reference_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(WmsQueueJobLog::class, 'queue_job_id');
    }

    /**
     * 次に処理すべきジョブを取得
     */
    public static function getNextPending(): ?self
    {
        return static::where('status', QueueJobStatus::PENDING)
            ->where(function ($query) {
                $query->where('attempts', '<', \DB::raw('max_attempts'));
            })
            ->orderBy('priority', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * 特定タイプの待機中ジョブを取得
     */
    public static function getPendingByType(QueueJobType $type): ?self
    {
        return static::where('job_type', $type)
            ->where('status', QueueJobStatus::PENDING)
            ->where(function ($query) {
                $query->where('attempts', '<', \DB::raw('max_attempts'));
            })
            ->orderBy('priority', 'asc')
            ->orderBy('created_at', 'asc')
            ->first();
    }

    /**
     * 処理開始をマーク
     */
    public function markAsProcessing(): self
    {
        $this->update([
            'status' => QueueJobStatus::PROCESSING,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);

        return $this;
    }

    /**
     * 処理完了をマーク
     */
    public function markAsCompleted(array $result = []): self
    {
        $this->update([
            'status' => QueueJobStatus::COMPLETED,
            'completed_at' => now(),
            'result' => $result,
        ]);

        return $this;
    }

    /**
     * 処理失敗をマーク
     */
    public function markAsFailed(string $errorMessage, array $result = []): self
    {
        $status = $this->attempts >= $this->max_attempts
            ? QueueJobStatus::FAILED
            : QueueJobStatus::PENDING;

        $this->update([
            'status' => $status,
            'error_message' => $errorMessage,
            'result' => $result,
            'completed_at' => now(),
        ]);

        return $this;
    }

    /**
     * ログを追加
     */
    public function addLog(string $level, string $message, array $context = []): WmsQueueJobLog
    {
        return $this->logs()->create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
