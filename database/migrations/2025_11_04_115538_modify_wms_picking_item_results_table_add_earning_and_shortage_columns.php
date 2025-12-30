<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Modifications per 02_picking_2.md specification:
     * 1. Add earning_id (伝票id)
     * 2. Simplify status to: PENDING, PICKING, COMPLETED (remove SHORTAGE)
     * 3. Update has_physical_shortage logic: status == COMPLETED AND planned_qty > picked_qty
     * 4. Add has_soft_shortage: ordered_qty > planned_qty
     * 5. Add has_shortage: has_physical_shortage OR has_soft_shortage
     */
    public function up(): void
    {
        // Step 1: Add earning_id column
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->unsignedBigInteger('earning_id')
                ->after('picking_task_id')
                ->nullable()
                ->comment('売上伝票ID (earnings.id)');
        });

        // Step 2: Drop existing has_physical_shortage column
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('has_physical_shortage');
        });

        // Step 3: Modify status enum to remove SHORTAGE, add PENDING
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN status ENUM('PENDING', 'PICKING', 'COMPLETED')
            DEFAULT 'PENDING'
            COMMENT 'ステータス（PENDING: 初期状態, PICKING: ピッキング中, COMPLETED: ピッキング完了）'
        ");

        // Step 4: Add quantity type columns for ordered, planned, and picked
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            // Add quantity type columns if they don't exist
            if (!Schema::connection('sakemaru')->hasColumn('wms_picking_item_results', 'ordered_qty_type')) {
                $table->enum('ordered_qty_type', ['CASE', 'PIECE'])
                    ->after('ordered_qty')
                    ->default('PIECE')
                    ->comment('発注数量タイプ');
            }
            if (!Schema::connection('sakemaru')->hasColumn('wms_picking_item_results', 'planned_qty_type')) {
                $table->enum('planned_qty_type', ['CASE', 'PIECE'])
                    ->after('planned_qty')
                    ->default('PIECE')
                    ->comment('計画数量タイプ');
            }
            if (!Schema::connection('sakemaru')->hasColumn('wms_picking_item_results', 'picked_qty_type')) {
                $table->enum('picked_qty_type', ['CASE', 'PIECE'])
                    ->after('picked_qty')
                    ->default('PIECE')
                    ->comment('実績数量タイプ');
            }
        });

        // Step 5: Add new generated columns with updated logic
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_physical_shortage BOOLEAN
            GENERATED ALWAYS AS (status = 'COMPLETED' AND planned_qty > picked_qty) STORED
            COMMENT '物理的欠品フラグ（status = COMPLETED かつ planned_qty > picked_qty の場合 TRUE）'
            AFTER shortage_qty
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_soft_shortage BOOLEAN
            GENERATED ALWAYS AS (ordered_qty > planned_qty) STORED
            COMMENT 'ソフト欠品フラグ（ordered_qty > planned_qty の場合 TRUE）'
            AFTER has_physical_shortage
        ");

        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_shortage BOOLEAN
            GENERATED ALWAYS AS (
                (status = 'COMPLETED' AND planned_qty > picked_qty) OR (ordered_qty > planned_qty)
            ) STORED
            COMMENT '欠品フラグ（has_physical_shortage OR has_soft_shortage）'
            AFTER has_soft_shortage
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove new generated columns
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn(['has_shortage', 'has_soft_shortage', 'has_physical_shortage']);
        });

        // Restore old status enum with SHORTAGE
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            MODIFY COLUMN status ENUM('PICKING', 'COMPLETED', 'SHORTAGE')
            DEFAULT 'PICKING'
            COMMENT 'ステータス'
        ");

        // Restore old has_physical_shortage logic
        DB::connection('sakemaru')->statement("
            ALTER TABLE wms_picking_item_results
            ADD COLUMN has_physical_shortage BOOLEAN
            GENERATED ALWAYS AS (planned_qty != picked_qty) STORED
            COMMENT '倉庫内実在庫相違フラグ（planned_qty ≠ picked_qty の場合 TRUE）'
            AFTER shortage_qty
        ");

        // Remove earning_id
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            $table->dropColumn('earning_id');
        });

        // Remove quantity type columns if they were added
        Schema::connection('sakemaru')->table('wms_picking_item_results', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_picking_item_results', 'ordered_qty_type')) {
                $table->dropColumn('ordered_qty_type');
            }
            if (Schema::connection('sakemaru')->hasColumn('wms_picking_item_results', 'planned_qty_type')) {
                $table->dropColumn('planned_qty_type');
            }
            if (Schema::connection('sakemaru')->hasColumn('wms_picking_item_results', 'picked_qty_type')) {
                $table->dropColumn('picked_qty_type');
            }
        });
    }
};
