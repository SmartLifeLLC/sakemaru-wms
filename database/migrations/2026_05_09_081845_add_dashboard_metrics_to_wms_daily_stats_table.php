<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_daily_stats', function (Blueprint $table) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'total_slip_count')) {
                $table->unsignedInteger('total_slip_count')->default(0)->after('target_date')->comment('当日受注伝票数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'shipped_slip_count')) {
                $table->unsignedInteger('shipped_slip_count')->default(0)->after('total_slip_count')->comment('出荷済み伝票数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'unshipped_slip_count')) {
                $table->unsignedInteger('unshipped_slip_count')->default(0)->after('shipped_slip_count')->comment('出荷前伝票数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'unique_buyer_count')) {
                $table->unsignedInteger('unique_buyer_count')->default(0)->after('unshipped_slip_count')->comment('ユニーク顧客数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'wave_count')) {
                $table->unsignedInteger('wave_count')->default(0)->after('delivery_course_count')->comment('波動数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'picking_task_count')) {
                $table->unsignedInteger('picking_task_count')->default(0)->after('wave_count')->comment('ピッキングタスク数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'completed_task_count')) {
                $table->unsignedInteger('completed_task_count')->default(0)->after('picking_task_count')->comment('完了タスク数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'shipped_task_count')) {
                $table->unsignedInteger('shipped_task_count')->default(0)->after('completed_task_count')->comment('出荷済みタスク数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'total_order_qty')) {
                $table->unsignedInteger('total_order_qty')->default(0)->after('total_ship_qty')->comment('指示数量合計');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'total_planned_qty')) {
                $table->unsignedInteger('total_planned_qty')->default(0)->after('total_order_qty')->comment('引当予定数量合計');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'allocation_shortage_qty')) {
                $table->unsignedInteger('allocation_shortage_qty')->default(0)->after('stockout_total_count')->comment('引当欠品数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'confirmed_shortage_count')) {
                $table->unsignedInteger('confirmed_shortage_count')->default(0)->after('allocation_shortage_qty')->comment('欠品確定明細数');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'confirmed_shortage_qty')) {
                $table->unsignedInteger('confirmed_shortage_qty')->default(0)->after('confirmed_shortage_count')->comment('欠品確定数量');
            }
            if (! Schema::connection('sakemaru')->hasColumn('wms_daily_stats', 'shortage_slip_count')) {
                $table->unsignedInteger('shortage_slip_count')->default(0)->after('confirmed_shortage_qty')->comment('欠品あり伝票数');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_daily_stats', function (Blueprint $table) {
            foreach ([
                'total_slip_count',
                'shipped_slip_count',
                'unshipped_slip_count',
                'unique_buyer_count',
                'wave_count',
                'picking_task_count',
                'completed_task_count',
                'shipped_task_count',
                'total_order_qty',
                'total_planned_qty',
                'allocation_shortage_qty',
                'confirmed_shortage_count',
                'confirmed_shortage_qty',
                'shortage_slip_count',
            ] as $column) {
                if (Schema::connection('sakemaru')->hasColumn('wms_daily_stats', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
