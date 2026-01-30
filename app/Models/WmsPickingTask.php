<?php

namespace App\Models;

use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Floor;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class WmsPickingTask extends Model
{
    protected $connection = 'sakemaru';

    protected $table = 'wms_picking_tasks';

    // Status constants
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PICKING_READY = 'PICKING_READY';

    public const STATUS_PICKING = 'PICKING';

    public const STATUS_COMPLETED = 'COMPLETED';

    protected $fillable = [
        'wave_id',
        'wms_picking_area_id',
        'warehouse_id',
        'warehouse_code',
        'floor_id',
        'temperature_type',
        'is_restricted_area',
        'delivery_course_id',
        'delivery_course_code',
        'shipment_date',
        'status',
        'task_type',
        'picker_id',
        'started_at',
        'completed_at',
        'print_requested_count',
    ];

    protected $casts = [
        'shipment_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'temperature_type' => \App\Enums\TemperatureType::class,
        'is_restricted_area' => 'boolean',
        'print_requested_count' => 'integer',
    ];

    /**
     * Boot the model
     */
    protected static function booted(): void
    {
        static::updated(function (WmsPickingTask $task) {
            if (! $task->isDirty('status')) {
                return;
            }

            // ステータスがPICKINGに変更されたときに、関連する伝票のpicking_statusを更新
            if ($task->status === self::STATUS_PICKING) {
                // このタスクに関連するearning_idを取得
                $earningIds = DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->where('picking_task_id', $task->id)
                    ->whereNotNull('earning_id')
                    ->distinct()
                    ->pluck('earning_id')
                    ->toArray();

                // 取得したearningのpicking_statusをPICKINGに更新
                if (! empty($earningIds)) {
                    DB::connection('sakemaru')
                        ->table('earnings')
                        ->whereIn('id', $earningIds)
                        ->update([
                            'picking_status' => 'PICKING',
                            'updated_at' => now(),
                        ]);
                }
            }

            // ステータスがCOMPLETEDに変更されたときに、関連する伝票のpicking_statusを更新
            // ただし、該当earning_idが含まれている全てのwms_picking_taskがCOMPLETEDである必要がある
            if ($task->status === self::STATUS_COMPLETED) {
                // このタスクに関連するearning_idを取得
                $earningIds = DB::connection('sakemaru')
                    ->table('wms_picking_item_results')
                    ->where('picking_task_id', $task->id)
                    ->whereNotNull('earning_id')
                    ->distinct()
                    ->pluck('earning_id')
                    ->toArray();

                if (empty($earningIds)) {
                    return;
                }

                // 各earning_idについて、全てのタスクが完了しているかチェック
                $completableEarningIds = [];
                foreach ($earningIds as $earningId) {
                    // このearning_idを含む全てのタスクを取得
                    $relatedTaskIds = DB::connection('sakemaru')
                        ->table('wms_picking_item_results')
                        ->where('earning_id', $earningId)
                        ->distinct()
                        ->pluck('picking_task_id')
                        ->toArray();

                    // 全てのタスクがCOMPLETEDかチェック
                    $incompleteTasks = DB::connection('sakemaru')
                        ->table('wms_picking_tasks')
                        ->whereIn('id', $relatedTaskIds)
                        ->where('status', '!=', self::STATUS_COMPLETED)
                        ->count();

                    // 全てのタスクが完了していれば、このearningをCOMPLETEDに更新可能
                    if ($incompleteTasks === 0) {
                        $completableEarningIds[] = $earningId;
                    }
                }

                // 全てのタスクが完了しているearningのpicking_statusをCOMPLETEDに更新
                if (! empty($completableEarningIds)) {
                    DB::connection('sakemaru')
                        ->table('earnings')
                        ->whereIn('id', $completableEarningIds)
                        ->update([
                            'picking_status' => 'COMPLETED',
                            'updated_at' => now(),
                        ]);
                }
            }
        });
    }

    /**
     * このタスクが属するウェーブ
     */
    public function wave(): BelongsTo
    {
        return $this->belongsTo(Wave::class, 'wave_id');
    }

    /**
     * このタスクが属するピッキングエリア
     */
    public function pickingArea(): BelongsTo
    {
        return $this->belongsTo(WmsPickingArea::class, 'wms_picking_area_id');
    }

    /**
     * このタスクが属する倉庫
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * このタスクが属する倉庫フロア
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id');
    }

    /**
     * このタスクが属する配送コース
     */
    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class, 'delivery_course_id');
    }

    /**
     * このタスクのピッキング明細
     */
    public function pickingItemResults(): HasMany
    {
        return $this->hasMany(WmsPickingItemResult::class, 'picking_task_id');
    }

    /**
     * ピッカー（担当者）
     */
    public function picker(): BelongsTo
    {
        return $this->belongsTo(WmsPicker::class, 'picker_id');
    }

    /**
     * スコープ：未割当タスク
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('picker_id');
    }

    /**
     * スコープ：進行中ステータス
     */
    public function scopeInProgress($query)
    {
        return $query->whereIn('status', ['PENDING', 'PICKING_READY', 'PICKING']);
    }

    /**
     * Get display-friendly wave code
     */
    public function getWaveCodeAttribute(): string
    {
        return $this->wave->wave_code ?? "Wave {$this->wave_id}";
    }

    /**
     * Get total item count for this task
     */
    public function getItemCountAttribute(): int
    {
        return $this->pickingItemResults()->count();
    }

    /**
     * 引当欠品があるかどうか（picking_item_results.has_soft_shortage経由）
     */
    public function hasSoftShortage(): bool
    {
        return $this->pickingItemResults()
            ->where('has_soft_shortage', true)
            ->exists();
    }

    /**
     * 引当欠品のある明細数を取得
     * withCount で事前ロードされている場合はその値を使用
     */
    public function getSoftShortageCountAttribute(): int
    {
        // withCount でロード済みの場合はそれを使用
        if (array_key_exists('soft_shortage_count', $this->attributes)) {
            return (int) $this->attributes['soft_shortage_count'];
        }

        return $this->pickingItemResults()
            ->where('has_soft_shortage', true)
            ->count();
    }
}
