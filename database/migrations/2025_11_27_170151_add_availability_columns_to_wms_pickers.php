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
        Schema::connection('sakemaru')->table('wms_pickers', function (Blueprint $table) {
            if (!Schema::connection('sakemaru')->hasColumn('wms_pickers', 'is_available_for_picking')) {
                $table->boolean('is_available_for_picking')->default(false)->after('picking_speed_rate')
                    ->comment('当日ピッキング稼働可否 (出勤かつ割当可ならtrue)');
            }
            if (!Schema::connection('sakemaru')->hasColumn('wms_pickers', 'current_warehouse_id')) {
                $table->unsignedBigInteger('current_warehouse_id')->nullable()->after('is_available_for_picking')
                    ->comment('現在稼働中の倉庫ID (NULLなら未割当)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_pickers', function (Blueprint $table) {
            if (Schema::connection('sakemaru')->hasColumn('wms_pickers', 'is_available_for_picking')) {
                $table->dropColumn('is_available_for_picking');
            }
            if (Schema::connection('sakemaru')->hasColumn('wms_pickers', 'current_warehouse_id')) {
                $table->dropColumn('current_warehouse_id');
            }
        });
    }
};
