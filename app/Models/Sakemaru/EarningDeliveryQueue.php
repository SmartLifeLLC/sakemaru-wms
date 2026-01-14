<?php

namespace App\Models\Sakemaru;

class EarningDeliveryQueue extends SakemaruModel
{
    protected $guarded = [];

    protected $table = 'earning_delivery_queue';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const MAX_RETRY_COUNT = 3;

    protected $casts = [
        'earning_ids' => 'array',
        'items' => 'array',
        'retry_count' => 'integer',
        'next_retry_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * 処理待ちのキューを取得
     */
    public function scopePending($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * 処理中としてマーク
     */
    public function markAsProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->save();
    }

    /**
     * 処理完了としてマーク
     */
    public function markAsCompleted(): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->processed_at = now();
        $this->save();
    }

    /**
     * 処理失敗としてマーク（リトライ可能な場合は再スケジュール）
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->retry_count++;
        $this->error_message = $errorMessage;

        if ($this->retry_count >= self::MAX_RETRY_COUNT) {
            $this->status = self::STATUS_FAILED;
        } else {
            $this->status = self::STATUS_PENDING;
            // 指数バックオフ: 1分, 4分, 9分...
            $delayMinutes = pow($this->retry_count, 2);
            $this->next_retry_at = now()->addMinutes($delayMinutes);
        }

        $this->save();
    }

    /**
     * 売上IDの配列を取得
     */
    public function getEarningIdsArray(): array
    {
        return $this->earning_ids ?? [];
    }

    /**
     * アイテム情報の配列を取得
     */
    public function getItemsArray(): array
    {
        return $this->items ?? [];
    }
}
