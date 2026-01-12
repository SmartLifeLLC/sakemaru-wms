<?php

namespace App\Models;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * 自動発注ジョブ管理モデル
 *
 * @property int $id
 * @property string $process_name
 * @property string $batch_code
 * @property string $status
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property array|null $target_scope
 * @property int|null $total_records
 * @property int|null $processed_records
 * @property string|null $error_details
 */
class WmsAutoOrderJobControl extends WmsModel
{
    protected $table = 'wms_auto_order_job_controls';

    protected $fillable = [
        'process_name',
        'batch_code',
        'status',
        'started_at',
        'finished_at',
        'target_scope',
        'total_records',
        'processed_records',
        'error_details',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'target_scope' => 'array',
        'status' => JobStatus::class,
        'process_name' => JobProcessName::class,
    ];

    /**
     * 新しいバッチコードを生成
     */
    public static function generateBatchCode(): string
    {
        return now()->format('YmdHis');
    }

    /**
     * ジョブを開始
     */
    public static function startJob(JobProcessName $processName, ?array $scope = null): self
    {
        return self::create([
            'process_name' => $processName,
            'batch_code' => self::generateBatchCode(),
            'status' => JobStatus::RUNNING,
            'started_at' => now(),
            'target_scope' => $scope,
        ]);
    }

    /**
     * ジョブを成功で完了
     */
    public function markAsSuccess(int $processedRecords = 0): void
    {
        $this->update([
            'status' => JobStatus::SUCCESS,
            'finished_at' => now(),
            'processed_records' => $processedRecords,
        ]);
    }

    /**
     * ジョブを失敗で完了
     */
    public function markAsFailed(string $errorDetails): void
    {
        $this->update([
            'status' => JobStatus::FAILED,
            'finished_at' => now(),
            'error_details' => $errorDetails,
        ]);
    }

    /**
     * 進捗を更新
     */
    public function updateProgress(int $processed, int $total): void
    {
        $this->update([
            'processed_records' => $processed,
            'total_records' => $total,
        ]);
    }

    /**
     * 実行中のジョブがあるかチェック
     */
    public static function hasRunningJob(JobProcessName $processName): bool
    {
        return self::where('process_name', $processName)
            ->where('status', JobStatus::RUNNING)
            ->exists();
    }

    /**
     * 今日のバッチを取得
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('started_at', today());
    }

    /**
     * 進捗率を取得
     */
    public function getProgressPercentageAttribute(): ?int
    {
        if (! $this->total_records || $this->total_records === 0) {
            return null;
        }

        return (int) round(($this->processed_records / $this->total_records) * 100);
    }
}
