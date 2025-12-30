<?php

namespace App\Models;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\LotStatus;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Contractor;
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

    protected $fillable = [
        'batch_code',
        'satellite_warehouse_id',
        'hub_warehouse_id',
        'item_id',
        'contractor_id',
        'suggested_quantity',
        'transfer_quantity',
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
    ];

    protected $casts = [
        'expected_arrival_date' => 'date',
        'original_arrival_date' => 'date',
        'modified_at' => 'datetime',
        'status' => CandidateStatus::class,
        'lot_status' => LotStatus::class,
        'quantity_type' => QuantityType::class,
        'is_manually_modified' => 'boolean',
        'lot_fee_amount' => 'decimal:2',
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
}
