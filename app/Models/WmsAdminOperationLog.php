<?php

namespace App\Models;

use App\Enums\EWMSLogOperationType;
use App\Enums\EWMSLogTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WmsAdminOperationLog extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_admin_operation_logs';

    public $timestamps = false; // Using created_at only

    protected $fillable = [
        'user_id',
        'operation_type',
        'target_type',
        'target_id',
        'picking_task_id',
        'picking_item_result_id',
        'wave_id',
        'earning_id',
        'picker_id_before',
        'picker_id_after',
        'delivery_course_id_before',
        'delivery_course_id_after',
        'warehouse_id_before',
        'warehouse_id_after',
        'qty_before',
        'qty_after',
        'qty_type',
        'status_before',
        'status_after',
        'operation_details',
        'operation_note',
        'affected_count',
        'affected_ids',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'operation_type' => EWMSLogOperationType::class,
        'target_type' => EWMSLogTargetType::class,
        'operation_details' => 'array',
        'affected_ids' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user that performed the action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    /**
     * Get the picking task
     */
    public function pickingTask(): BelongsTo
    {
        return $this->belongsTo(WmsPickingTask::class, 'picking_task_id');
    }

    /**
     * 管理画面操作のログを記録
     *
     * @param EWMSLogOperationType $operationType 操作種類
     * @param array $data ログデータ
     * @return static
     */
    public static function log(EWMSLogOperationType $operationType, array $data = []): self
    {
        $user = auth()->user();

        $logData = [
            'operation_type' => $operationType,
            'user_id' => $user?->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];

        // データをマージ
        $logData = array_merge($logData, $data);

        return self::create($logData);
    }

    /**
     * アクセサー: operation_type のラベル
     */
    public function getOperationTypeLabelAttribute(): string
    {
        return $this->operation_type?->label() ?? '';
    }

    /**
     * アクセサー: target_type のラベル
     */
    public function getTargetTypeLabelAttribute(): string
    {
        return $this->target_type?->label() ?? '';
    }
}
