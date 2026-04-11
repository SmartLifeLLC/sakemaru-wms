<?php

namespace App\Models;

use App\Enums\QueueProgressStatus;
use Illuminate\Support\Str;

/**
 * キュー処理進捗管理モデル
 *
 * 様々なバックグラウンド処理の進捗を管理する共通テーブル
 */
class WmsQueueProgress extends WmsModel
{
    protected $table = 'wms_queue_progress';

    protected $fillable = [
        'job_type',
        'job_id',
        'user_id',
        'status',
        'progress',
        'total_items',
        'processed_items',
        'message',
        'result',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => QueueProgressStatus::class,
        'progress' => 'integer',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'result' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * ジョブ種別定数
     */
    public const JOB_TYPE_ORDER_CONFIRMATION = 'order_confirmation';

    public const JOB_TYPE_CSV_GENERATION = 'csv_generation';

    public const JOB_TYPE_JX_GENERATION = 'jx_generation';

    public const JOB_TYPE_ORDER_CANDIDATE_GENERATION = 'order_candidate_generation';

    public const JOB_TYPE_TEST_ORDER_FILES = 'test_order_files';

    public const JOB_TYPE_AUTO_SEND = 'auto_send';

    /**
     * 新しい進捗レコードを作成
     */
    public static function createJob(string $jobType, ?int $userId = null, array $metadata = []): self
    {
        return self::create([
            'job_type' => $jobType,
            'job_id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'status' => QueueProgressStatus::PENDING,
            'progress' => 0,
            'total_items' => 0,
            'processed_items' => 0,
            'metadata' => $metadata,
        ]);
    }

    /**
     * 処理開始をマーク
     */
    public function markAsProcessing(int $totalItems = 0, ?string $message = null): self
    {
        $this->update([
            'status' => QueueProgressStatus::PROCESSING,
            'total_items' => $totalItems,
            'message' => $message ?? '処理を開始しました',
            'started_at' => now(),
        ]);

        return $this;
    }

    /**
     * 進捗を更新
     */
    public function updateProgress(int $processedItems, ?string $message = null): self
    {
        $progress = $this->total_items > 0
            ? min(100, (int) round(($processedItems / $this->total_items) * 100))
            : 0;

        $updateData = [
            'processed_items' => $processedItems,
            'progress' => $progress,
        ];

        if ($message !== null) {
            $updateData['message'] = $message;
        }

        $this->update($updateData);

        return $this;
    }

    /**
     * 処理完了をマーク
     */
    public function markAsCompleted(array $result = [], ?string $message = null): self
    {
        $this->update([
            'status' => QueueProgressStatus::COMPLETED,
            'progress' => 100,
            'processed_items' => $this->total_items,
            'result' => $result,
            'message' => $message ?? '処理が完了しました',
            'completed_at' => now(),
        ]);

        return $this;
    }

    /**
     * 処理失敗をマーク
     */
    public function markAsFailed(string $errorMessage, array $result = []): self
    {
        $this->update([
            'status' => QueueProgressStatus::FAILED,
            'result' => $result,
            'message' => $errorMessage,
            'completed_at' => now(),
        ]);

        return $this;
    }

    /**
     * ユーザーの進行中ジョブを取得
     */
    public static function getActiveJobForUser(string $jobType, int $userId): ?self
    {
        return self::where('job_type', $jobType)
            ->where('user_id', $userId)
            ->whereIn('status', [QueueProgressStatus::PENDING, QueueProgressStatus::PROCESSING])
            ->latest()
            ->first();
    }

    /**
     * ジョブIDで取得
     */
    public static function findByJobId(string $jobId): ?self
    {
        return self::where('job_id', $jobId)->first();
    }

    /**
     * 処理中かどうか
     */
    public function isProcessing(): bool
    {
        return $this->status === QueueProgressStatus::PROCESSING;
    }

    /**
     * 完了したかどうか
     */
    public function isCompleted(): bool
    {
        return $this->status === QueueProgressStatus::COMPLETED;
    }

    /**
     * 失敗したかどうか
     */
    public function isFailed(): bool
    {
        return $this->status === QueueProgressStatus::FAILED;
    }

    /**
     * アクティブかどうか（待機中または処理中）
     */
    public function isActive(): bool
    {
        return in_array($this->status, [QueueProgressStatus::PENDING, QueueProgressStatus::PROCESSING]);
    }
}
