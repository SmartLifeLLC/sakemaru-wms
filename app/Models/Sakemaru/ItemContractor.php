<?php

namespace App\Models\Sakemaru;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemContractor extends CustomModel
{
    protected $guarded = [];

    protected $casts = [
        'safety_stock' => 'integer',
        'max_stock' => 'integer',
        'min_stock' => 'integer',
        'purchase_unit' => 'integer',
        'auto_order_quantity' => 'integer',
        'is_auto_order' => 'boolean',
        'use_safety_stock_auto_update' => 'boolean',
    ];

    /**
     * item_contractors テーブルには is_active カラムがない
     */
    protected bool $hasIsActiveColumn = false;

    public function save(array $options = [])
    {
        return $this->getConnection()->transaction(function () use ($options) {
            $db = $this->getConnection();
            if ($db->getSchemaBuilder()->hasTable('wms_auto_order_controls')) {
                $control = $db->table('wms_auto_order_controls')->where('client_id', $this->client_id)->lockForUpdate()->first();
                if ($control) {
                    if ($control->reset_state === 'IN_PROGRESS') {
                        throw new \RuntimeException('自動発注設定の初期化中です。完了後に再実行してください。');
                    }
                    if (! $this->exists) {
                        $preserved = \App\Support\AutoReplenishmentResetScope::preservedWarehouseIds($db, $control);
                        $columns = in_array((int) $this->warehouse_id, $preserved, true)
                            ? ['is_auto_order', 'use_safety_stock_auto_update']
                            : ['safety_stock', 'max_stock', 'min_stock', 'purchase_unit', 'auto_order_quantity', 'is_auto_order', 'use_safety_stock_auto_update'];
                        foreach ($columns as $column) {
                            $this->setAttribute($column, 0);
                        }
                    } elseif ($this->is_auto_order || $this->use_safety_stock_auto_update) {
                        $this->is_auto_order = false;
                        $this->use_safety_stock_auto_update = false;
                    }
                }
            }

            return parent::save($options);
        }, 3);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse_contractors(): HasMany
    {
        return $this->hasMany(WarehouseContractor::class, 'contractor_id', 'contractor_id');
    }
}
