<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ロット調節ツールの実行ログ（1回の実行＝1レコード）。
 *
 * 在庫データ（real_stocks / real_stock_lots / stla）は変更しない。
 * 実行前後の状態を details(JSON) に記録し、画面で履歴を確認できるようにする。
 */
class WmsLotAdjustmentLog extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_lot_adjustment_logs';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'run_uuid',
        'mode',
        'user_id',
        'warehouse_id',
        'scope',
        'summary',
        'affected_count',
        'details',
        'note',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'scope' => 'array',
        'summary' => 'array',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Sakemaru\Warehouse::class, 'warehouse_id');
    }

    /**
     * ロット調節の実行ログを記録する。
     *
     * @param  string  $mode  'DRY_RUN' | 'APPLIED'
     * @param  array  $data  warehouse_id, scope, summary, affected_count, details, note 等
     */
    public static function record(string $mode, array $data = []): self
    {
        $user = auth()->user();

        $logData = array_merge([
            'run_uuid' => $data['run_uuid'] ?? (string) Str::uuid(),
            'mode' => $mode,
            'user_id' => $user?->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $data);

        return self::create($logData);
    }
}
