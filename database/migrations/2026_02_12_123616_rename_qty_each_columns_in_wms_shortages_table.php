<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * wms_shortagesテーブルのカラム名を _each なしに統一する
     */
    public function up(): void
    {
        DB::connection('sakemaru')->statement('
            ALTER TABLE wms_shortages
            CHANGE COLUMN order_qty_each order_qty INT NOT NULL COMMENT "受注数量(PIECE換算)",
            CHANGE COLUMN planned_qty_each planned_qty INT NOT NULL DEFAULT 0 COMMENT "引当数量(PIECE)",
            CHANGE COLUMN picked_qty_each picked_qty INT NOT NULL DEFAULT 0 COMMENT "ピッキング数量(PIECE)",
            CHANGE COLUMN shortage_qty_each shortage_qty INT NOT NULL COMMENT "不足数量(PIECE)"
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::connection('sakemaru')->statement('
            ALTER TABLE wms_shortages
            CHANGE COLUMN order_qty order_qty_each INT NOT NULL COMMENT "受注数量(PIECE換算)",
            CHANGE COLUMN planned_qty planned_qty_each INT NOT NULL DEFAULT 0 COMMENT "引当数量(PIECE)",
            CHANGE COLUMN picked_qty picked_qty_each INT NOT NULL DEFAULT 0 COMMENT "ピッキング数量(PIECE)",
            CHANGE COLUMN shortage_qty shortage_qty_each INT NOT NULL COMMENT "不足数量(PIECE)"
        ');
    }
};
