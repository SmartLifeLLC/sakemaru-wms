<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_admin_operation_logs', function (Blueprint $table) {
            // operation_type を ENUM に変更
            $table->enum('operation_type', [
                'ASSIGN_PICKER',
                'UNASSIGN_PICKER',
                'CHANGE_DELIVERY_COURSE',
                'CHANGE_WAREHOUSE',
                'ADJUST_PICKING_QTY',
                'REVERT_PICKING',
                'PRINT_SHIPMENT_SLIP',
                'FORCE_PRINT_SHIPMENT_SLIP',
                'FORCE_SHIP',
            ])->comment('操作種類')->change();

            // target_type を ENUM に変更
            $table->enum('target_type', [
                'picking_task',
                'picking_item',
                'wave',
                'earning',
            ])->nullable()->comment('対象タイプ')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_admin_operation_logs', function (Blueprint $table) {
            $table->string('operation_type', 50)->comment('操作種類')->change();
            $table->string('target_type', 50)->nullable()->comment('対象タイプ')->change();
        });
    }
};
