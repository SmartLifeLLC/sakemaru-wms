<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ピッキングタスク構造の変更：
     * 1. wms_picking_item_resultsにtrade_id追加（タスクのtrade_idからコピー）
     * 2. wms_picking_tasksにfloor_id追加
     * 3. wms_picking_tasksからtrade_id削除
     */
    public function up(): void
    {
        $connection = 'sakemaru';

        // Step 1: wms_picking_item_resultsにtrade_idを追加（nullable、一時的）
        Schema::connection($connection)->table('wms_picking_item_results', function (Blueprint $table) {
            $table->bigInteger('trade_id')->nullable()->after('earning_id')
                ->comment('取引ID（売上明細が属する売上伝票）');
        });

        // Step 2: 既存データのtrade_idを移行（picking_taskから取得）
        DB::connection($connection)->statement("
            UPDATE wms_picking_item_results pir
            INNER JOIN wms_picking_tasks pt ON pir.picking_task_id = pt.id
            SET pir.trade_id = pt.trade_id
            WHERE pir.trade_id IS NULL AND pt.trade_id IS NOT NULL
        ");

        // Step 3: trade_idをNOT NULLに変更
        Schema::connection($connection)->table('wms_picking_item_results', function (Blueprint $table) {
            $table->bigInteger('trade_id')->nullable(false)->change();
        });

        // Step 4: wms_picking_tasksにfloor_idを追加（nullable）
        Schema::connection($connection)->table('wms_picking_tasks', function (Blueprint $table) {
            $table->bigInteger('floor_id')->nullable()->after('warehouse_code')
                ->comment('倉庫フロアID');
        });

        // Step 5: 既存データのfloor_idを推測して設定（最初のアイテムのロケーションから）
        if(Schema::connection($connection)->hasTable('locations')){
            DB::connection($connection)->statement("
                UPDATE wms_picking_tasks pt
                INNER JOIN (
                    SELECT pir.picking_task_id, l.floor_id
                    FROM wms_picking_item_results pir
                    INNER JOIN locations l ON pir.location_id = l.id
                    GROUP BY pir.picking_task_id, l.floor_id
                ) first_item ON pt.id = first_item.picking_task_id
                SET pt.floor_id = first_item.floor_id
                WHERE pt.floor_id IS NULL
            ");
        }

        // Step 6: wms_picking_tasksからtrade_idを削除
        Schema::connection($connection)->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropColumn('trade_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = 'sakemaru';

        // Reverse Step 6: wms_picking_tasksにtrade_idを戻す
        Schema::connection($connection)->table('wms_picking_tasks', function (Blueprint $table) {
            $table->bigInteger('trade_id')->nullable()->after('shipment_date');
        });

        // データを復元（picking_item_resultsから最初のtrade_idを取得）
        DB::connection($connection)->statement("
            UPDATE wms_picking_tasks pt
            INNER JOIN (
                SELECT picking_task_id, MIN(trade_id) as trade_id
                FROM wms_picking_item_results
                GROUP BY picking_task_id
            ) first_item ON pt.id = first_item.picking_task_id
            SET pt.trade_id = first_item.trade_id
        ");

        // Reverse Step 4: wms_picking_tasksからfloor_idを削除
        Schema::connection($connection)->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropColumn('floor_id');
        });

        // Reverse Step 1: wms_picking_item_resultsからtrade_idを削除
        Schema::connection($connection)->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('trade_id');
        });
    }
};
