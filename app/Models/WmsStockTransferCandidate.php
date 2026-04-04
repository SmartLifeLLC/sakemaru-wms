<?php

namespace App\Models;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\AutoOrder\OriginType;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\DeliveryCourse;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 倉庫間移動候補
 */
class WmsStockTransferCandidate extends WmsModel
{
    protected $table = 'wms_stock_transfer_candidates';

    /**
     * 手動でセットされた計算ログ（N+1対策）
     */
    protected ?WmsOrderCalculationLog $preloadedCalculationLog = null;

    protected bool $calculationLogPreloaded = false;

    protected $fillable = [
        'batch_code',
        'satellite_warehouse_id',
        'hub_warehouse_id',
        'item_id',
        'item_code',
        'search_code',
        'contractor_id',
        'delivery_course_id',
        'suggested_quantity',
        'transfer_quantity',
        'current_effective_stock',
        'incoming_quantity',
        'calculated_available',
        'shortage_qty',
        'safety_stock',
        'purchase_unit',
        'quantity_type',
        'expected_arrival_date',
        'original_arrival_date',
        'shipment_date',
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
        'origin_type',
        'exclusion_reason',
    ];

    protected $casts = [
        'expected_arrival_date' => 'date',
        'original_arrival_date' => 'date',
        'shipment_date' => 'date',
        'modified_at' => 'datetime',
        'status' => CandidateStatus::class,
        'lot_status' => LotStatus::class,
        'quantity_type' => QuantityType::class,
        'is_manually_modified' => 'boolean',
        'origin_type' => OriginType::class,
        'lot_fee_amount' => 'decimal:2',
        'current_effective_stock' => 'integer',
        'incoming_quantity' => 'integer',
        'calculated_available' => 'integer',
        'shortage_qty' => 'integer',
        'safety_stock' => 'integer',
        'purchase_unit' => 'integer',
    ];

    public function satelliteWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'satellite_warehouse_id');
    }

    public function hubWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'hub_warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function deliveryCourse(): BelongsTo
    {
        return $this->belongsTo(DeliveryCourse::class);
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
            ->where('warehouse_id', $this->satellite_warehouse_id)
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

        // batch_code + satellite_warehouse_id でグループ化し、item_id を WHERE IN で取得
        $grouped = $candidates->groupBy(fn ($c) => "{$c->batch_code}_{$c->satellite_warehouse_id}");

        $logs = collect();

        foreach ($grouped as $groupKey => $group) {
            $first = $group->first();
            $itemIds = $group->pluck('item_id')->unique()->values()->toArray();

            $groupLogs = WmsOrderCalculationLog::where('batch_code', $first->batch_code)
                ->where('warehouse_id', $first->satellite_warehouse_id)
                ->whereIn('item_id', $itemIds)
                ->get();

            $logs = $logs->merge($groupLogs);
        }

        // キーでインデックス化
        $logsIndexed = $logs->keyBy(fn ($log) => "{$log->batch_code}_{$log->warehouse_id}_{$log->item_id}");

        // 各候補にログをセット
        $candidates->each(function ($candidate) use ($logsIndexed) {
            $key = "{$candidate->batch_code}_{$candidate->satellite_warehouse_id}_{$candidate->item_id}";
            $candidate->setPreloadedCalculationLog($logsIndexed->get($key));
        });
    }

    /**
     * 発注点（安全在庫）を取得
     * 直接カラムから取得、なければcalculationLogにフォールバック
     */
    public function getEffectiveSafetyStockAttribute(): ?int
    {
        // 直接カラムがあればそちらを使用
        if (($this->attributes['safety_stock'] ?? null) !== null) {
            return $this->attributes['safety_stock'];
        }

        return $this->calculationLog?->safety_stock_setting ?? null;
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
     * 計算後在庫と不足数を再計算
     */
    public function recalculateStock(): void
    {
        $effectiveStock = $this->current_effective_stock ?? 0;
        $incoming = $this->incoming_quantity ?? 0;
        $safetyStock = $this->safety_stock ?? 0;

        $this->calculated_available = $effectiveStock + $incoming;
        $this->shortage_qty = max(0, $safetyStock - $this->calculated_available);
    }

    /**
     * 関連する発注候補を取得
     * Hub倉庫（移動元）の発注候補で、同一バッチ・商品のもの
     */
    public function getRelatedOrderCandidateAttribute(): ?WmsOrderCandidate
    {
        return WmsOrderCandidate::where('batch_code', $this->batch_code)
            ->where('warehouse_id', $this->hub_warehouse_id)
            ->where('item_id', $this->item_id)
            ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
            ->first();
    }
}
