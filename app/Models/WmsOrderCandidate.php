<?php

namespace App\Models;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\QuantityType;
use App\Models\Concerns\HasOptimisticLock;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 発注候補
 */
class WmsOrderCandidate extends WmsModel
{
    use HasOptimisticLock;

    protected $table = 'wms_order_candidates';

    /**
     * 手動でセットされた計算ログ（N+1対策）
     */
    protected ?WmsOrderCalculationLog $preloadedCalculationLog = null;

    protected bool $calculationLogPreloaded = false;

    protected $fillable = [
        'batch_code',
        'warehouse_id',
        'item_id',
        'contractor_id',
        'ordering_code',
        'self_shortage_qty',
        'satellite_demand_qty',
        'demand_breakdown',
        'origin_warehouse_ids',
        'suggested_quantity',
        'order_quantity',
        'quantity_type',
        'expected_arrival_date',
        'original_arrival_date',
        'status',
        'lot_status',
        'lot_rule_id',
        'lot_exception_id',
        'lot_before_qty',
        'lot_after_qty',
        'lot_fee_type',
        'lot_fee_amount',
        'is_manually_modified',
        'modified_by',
        'modified_at',
        'exclusion_reason',
        'transmission_status',
        'transmitted_at',
        'wms_order_jx_document_id',
        'lock_version',
    ];

    protected $casts = [
        'expected_arrival_date' => 'date',
        'original_arrival_date' => 'date',
        'modified_at' => 'datetime',
        'transmitted_at' => 'datetime',
        'status' => CandidateStatus::class,
        'lot_status' => LotStatus::class,
        'quantity_type' => QuantityType::class,
        'is_manually_modified' => 'boolean',
        'lot_fee_amount' => 'decimal:2',
        'demand_breakdown' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * 計算ログを事前にセット（N+1対策）
     */
    public function setPreloadedCalculationLog(?WmsOrderCalculationLog $log): self
    {
        $this->preloadedCalculationLog = $log;
        $this->calculationLogPreloaded = true;

        return $this;
    }

    /**
     * 計算ログを取得（プリロード済みの場合はキャッシュを使用）
     */
    public function getCalculationLogAttribute(): ?WmsOrderCalculationLog
    {
        // プリロード済みの場合はキャッシュを返す
        if ($this->calculationLogPreloaded) {
            return $this->preloadedCalculationLog;
        }

        // プリロードされていない場合はクエリを実行
        return WmsOrderCalculationLog::where('batch_code', $this->batch_code)
            ->where('warehouse_id', $this->warehouse_id)
            ->where('item_id', $this->item_id)
            ->first();
    }

    /**
     * コレクションに対して計算ログを一括プリロード
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, self>  $candidates
     */
    public static function preloadCalculationLogs($candidates): void
    {
        if ($candidates->isEmpty()) {
            return;
        }

        // 複合キーの組み合わせを収集
        $keys = $candidates->map(fn ($c) => [
            'batch_code' => $c->batch_code,
            'warehouse_id' => $c->warehouse_id,
            'item_id' => $c->item_id,
        ])->unique(fn ($k) => "{$k['batch_code']}_{$k['warehouse_id']}_{$k['item_id']}");

        // 一括でログを取得
        $query = WmsOrderCalculationLog::query();
        $keys->each(function ($key, $index) use ($query) {
            $method = $index === 0 ? 'where' : 'orWhere';
            $query->$method(function ($q) use ($key) {
                $q->where('batch_code', $key['batch_code'])
                    ->where('warehouse_id', $key['warehouse_id'])
                    ->where('item_id', $key['item_id']);
            });
        });

        $logs = $query->get()->keyBy(fn ($log) => "{$log->batch_code}_{$log->warehouse_id}_{$log->item_id}");

        // 各候補にログをセット
        $candidates->each(function ($candidate) use ($logs) {
            $key = "{$candidate->batch_code}_{$candidate->warehouse_id}_{$candidate->item_id}";
            $candidate->setPreloadedCalculationLog($logs->get($key));
        });
    }

    /**
     * 発注点（安全在庫）を取得
     */
    public function getSafetyStockAttribute(): ?int
    {
        return $this->calculationLog?->calculation_details['安全在庫'] ?? null;
    }

    public function scopeForBatch(Builder $query, string $batchCode): Builder
    {
        return $query->where('batch_code', $batchCode);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CandidateStatus::PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CandidateStatus::APPROVED);
    }

    public function scopeWithWarnings(Builder $query): Builder
    {
        return $query->whereIn('lot_status', [LotStatus::BLOCKED, LotStatus::NEED_APPROVAL]);
    }

    /**
     * 合計必要数を取得
     */
    public function getTotalRequiredAttribute(): int
    {
        return $this->self_shortage_qty + $this->satellite_demand_qty;
    }
}
