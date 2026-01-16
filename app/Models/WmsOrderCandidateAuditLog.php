<?php

namespace App\Models;

use App\Models\Sakemaru\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注候補監査ログ
 *
 * 発注候補に対する全ての変更を記録
 */
class WmsOrderCandidateAuditLog extends WmsModel
{
    protected $table = 'wms_order_candidate_audit_logs';

    protected $fillable = [
        'order_candidate_id',
        'batch_code',
        'action',
        'old_status',
        'new_status',
        'old_quantity',
        'new_quantity',
        'changes',
        'reason',
        'performed_by',
        'performed_by_name',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    // アクション定数
    public const ACTION_CREATED = 'created';

    public const ACTION_STATUS_CHANGED = 'status_changed';

    public const ACTION_QUANTITY_CHANGED = 'quantity_changed';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_EXCLUDED = 'excluded';

    public const ACTION_CONFIRMED = 'confirmed';

    public const ACTION_TRANSMITTED = 'transmitted';

    public const ACTION_APPROVAL_CANCELLED = 'approval_cancelled';

    public function orderCandidate(): BelongsTo
    {
        return $this->belongsTo(WmsOrderCandidate::class, 'order_candidate_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * アクションのラベルを取得
     */
    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_CREATED => '作成',
            self::ACTION_STATUS_CHANGED => 'ステータス変更',
            self::ACTION_QUANTITY_CHANGED => '数量変更',
            self::ACTION_APPROVED => '承認',
            self::ACTION_EXCLUDED => '除外',
            self::ACTION_CONFIRMED => '発注確定',
            self::ACTION_TRANSMITTED => '送信済',
            self::ACTION_APPROVAL_CANCELLED => '承認取消',
            default => $this->action,
        };
    }
}
