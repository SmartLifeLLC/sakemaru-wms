<?php

namespace App\Models;

use App\Models\Sakemaru\Contractor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 仕入先別自動発注実行ログ
 *
 * @property int $id
 * @property int $contractor_id
 * @property \Carbon\Carbon $executed_date
 * @property int|null $job_control_id
 * @property string $status RUNNING/SUCCESS/FAILED（候補生成）
 * @property string|null $transmission_status RUNNING/SUCCESS/FAILED（送信）
 * @property string|null $error_details
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
class WmsAutoOrderExecutionLog extends WmsModel
{
    protected $table = 'wms_auto_order_execution_log';

    protected $fillable = [
        'contractor_id',
        'executed_date',
        'job_control_id',
        'status',
        'transmission_status',
        'error_details',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'executed_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // Relationships

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function jobControl(): BelongsTo
    {
        return $this->belongsTo(WmsAutoOrderJobControl::class, 'job_control_id');
    }

    // Scopes

    public function scopeToday(Builder $query): Builder
    {
        return $query->where('executed_date', today());
    }

    public function scopeForContractor(Builder $query, int $contractorId): Builder
    {
        return $query->where('contractor_id', $contractorId);
    }

    // Methods

    /**
     * 指定仕入先が当日すでに実行済み（RUNNING/SUCCESS/FAILED）かチェック
     * FAILEDも含めることで、排他制御衝突等で失敗した仕入先の無限再ディスパッチを防ぐ
     */
    public static function hasRunOrSucceededToday(int $contractorId): bool
    {
        return self::latestForToday($contractorId) !== null;
    }

    public static function latestForToday(int $contractorId): ?self
    {
        return self::where('contractor_id', $contractorId)
            ->where('executed_date', today())
            ->whereIn('status', ['RUNNING', 'SUCCESS', 'FAILED'])
            ->latest('id')
            ->first();
    }

    /**
     * 成功で完了
     */
    public function markAsSuccess(?int $jobControlId = null): void
    {
        $data = [
            'status' => 'SUCCESS',
            'finished_at' => now(),
        ];

        if ($jobControlId !== null) {
            $data['job_control_id'] = $jobControlId;
        }

        $this->update($data);
    }

    /**
     * 失敗で完了
     */
    public function markAsFailed(string $errorDetails): void
    {
        $this->update([
            'status' => 'FAILED',
            'finished_at' => now(),
            'error_details' => $errorDetails,
        ]);
    }
}
