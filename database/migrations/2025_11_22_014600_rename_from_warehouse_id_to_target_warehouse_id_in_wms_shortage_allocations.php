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
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->renameColumn('from_warehouse_id', 'target_warehouse_id');
        });

        // インデックス名も更新
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropIndex('idx_alloc_warehouse');
            $table->index('target_warehouse_id', 'idx_alloc_target_warehouse');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropIndex('idx_alloc_target_warehouse');
            $table->index('from_warehouse_id', 'idx_alloc_warehouse');
        });

        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->renameColumn('target_warehouse_id', 'from_warehouse_id');
        });
    }
};
