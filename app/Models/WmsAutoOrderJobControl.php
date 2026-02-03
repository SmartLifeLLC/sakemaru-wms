<?php

namespace App\Models;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\JobStatus;
use App\Enums\AutoOrder\SettlementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property array|null $result_data
 */
class WmsAutoOrderJobControl extends WmsModel
{
    protected $table = 'wms_auto_order_job_controls';

    protected $fillable = [
        'process_name',
        'batch_code',
        'status',
        'settlement_status',
        'snapshot_job_id',
        'started_at',
        'finished_at',
        'target_scope',
        'total_records',
        'processed_records',
        'error_details',
        'result_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'target_scope' => 'array',
        'result_data' => 'array',
        'status' => JobStatus::class,
        'process_name' => JobProcessName::class,
        'settlement_status' => SettlementStatus::class,
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
     *
     * @param  JobProcessName  $processName  プロセス名
     * @param  array|null  $scope  対象スコープ
     * @param  string|null  $batchCode  バッチコード（指定がなければ自動生成）
     * @param  SettlementStatus  $settlementStatus  確定状態（デフォルト: PENDING）
     * @param  int|null  $snapshotJobId  参照する在庫スナップショットのjob_id
     */
    public static function startJob(
        JobProcessName $processName,
        ?array $scope = null,
        ?string $batchCode = null,
        SettlementStatus $settlementStatus = SettlementStatus::PENDING,
        ?int $snapshotJobId = null
    ): self {
        return self::create([
            'process_name' => $processName,
            'batch_code' => $batchCode ?? self::generateBatchCode(),
            'status' => JobStatus::RUNNING,
            'settlement_status' => $settlementStatus,
            'snapshot_job_id' => $snapshotJobId,
            'started_at' => now(),
            'target_scope' => $scope,
        ]);
    }

    /**
     * ジョブを成功で完了
     */
    public function markAsSuccess(int $processedRecords = 0, ?array $resultData = null): void
    {
        $data = [
            'status' => JobStatus::SUCCESS,
            'finished_at' => now(),
            'processed_records' => $processedRecords,
        ];

        if ($resultData !== null) {
            $data['result_data'] = $resultData;
        }

        $this->update($data);
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

    /**
     * 参照した在庫スナップショットジョブ
     */
    public function snapshotJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'snapshot_job_id');
    }

    /**
     * 確定待ち（PENDING）の最新ジョブを取得
     */
    public static function findPendingSettlement(): ?self
    {
        return self::where('settlement_status', SettlementStatus::PENDING)
            ->whereIn('process_name', [JobProcessName::STOCK_SNAPSHOT, JobProcessName::ORDER_CALC])
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * 確定待ち（PENDING）のジョブが存在するかチェック
     */
    public static function hasPendingSettlement(): bool
    {
        return self::where('settlement_status', SettlementStatus::PENDING)
            ->whereIn('process_name', [JobProcessName::STOCK_SNAPSHOT, JobProcessName::ORDER_CALC])
            ->exists();
    }

    /**
     * 確定待ち（PENDING）のジョブをキャンセル
     */
    public static function cancelPendingSettlements(): int
    {
        return self::where('settlement_status', SettlementStatus::PENDING)
            ->update(['settlement_status' => SettlementStatus::CANCELLED]);
    }

    /**
     * 確定状態を更新
     */
    public function markAsSettled(): void
    {
        $this->update(['settlement_status' => SettlementStatus::CONFIRMED]);
    }
}
