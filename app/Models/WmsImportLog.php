<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsImportLog extends WmsModel
{
    protected $table = 'wms_import_logs';

    protected $fillable = [
        'type',
        'status',
        'file_name',
        'user_id',
        'total_rows',
        'processed_rows',
        'success_count',
        'error_count',
        'errors',
        'message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Import types
    public const TYPE_MONTHLY_SAFETY_STOCKS = 'monthly_safety_stocks';

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * User relation
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\User::class, 'user_id');
    }

    /**
     * Scope for specific type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for pending imports
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for processing imports
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope for recent imports
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    /**
     * Mark as completed
     */
    public function markAsCompleted(int $successCount, int $errorCount, ?array $errors = null, ?string $message = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors ? array_slice($errors, 0, 100) : null,
            'message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $message, ?array $errors = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'message' => $message,
            'errors' => $errors ? array_slice($errors, 0, 100) : null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Update progress
     */
    public function updateProgress(int $processedRows): void
    {
        $this->update([
            'processed_rows' => $processedRows,
        ]);
    }

    /**
     * Check if import is in progress
     */
    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '待機中',
            self::STATUS_PROCESSING => '処理中',
            self::STATUS_COMPLETED => '完了',
            self::STATUS_FAILED => '失敗',
            default => $this->status,
        };
    }

    /**
     * Get status color for Filament
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'gray',
            self::STATUS_PROCESSING => 'warning',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }
}
