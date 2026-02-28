<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsExportLog extends WmsModel
{
    protected $table = 'wms_export_logs';

    protected $fillable = [
        'resource_name',
        'format',
        'status',
        'file_name',
        'file_path',
        'file_size',
        'row_count',
        'filters',
        'columns',
        'user_id',
        'error_message',
        'downloaded_at',
    ];

    protected $casts = [
        'format' => ExportFormat::class,
        'status' => ExportStatus::class,
        'filters' => 'array',
        'columns' => 'array',
        'file_size' => 'integer',
        'row_count' => 'integer',
        'downloaded_at' => 'datetime',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForResource(Builder $query, string $resourceName): Builder
    {
        return $query->where('resource_name', $resourceName);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ExportStatus::COMPLETED);
    }

    // Methods

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => ExportStatus::PROCESSING,
        ]);
    }

    public function markAsCompleted(string $filePath, int $fileSize, int $rowCount): void
    {
        $this->update([
            'status' => ExportStatus::COMPLETED,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'row_count' => $rowCount,
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => ExportStatus::FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    public function markAsDownloaded(): void
    {
        $this->update([
            'downloaded_at' => now(),
        ]);
    }

    public function isCompleted(): bool
    {
        return $this->status === ExportStatus::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === ExportStatus::FAILED;
    }

    public function isProcessing(): bool
    {
        return $this->status === ExportStatus::PROCESSING || $this->status === ExportStatus::PENDING;
    }

    public function getHumanFileSizeAttribute(): string
    {
        if (! $this->file_size) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 1).' '.$units[$unitIndex];
    }
}
