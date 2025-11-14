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
        Schema::connection('sakemaru')->table('wms_warehouse_layouts', function (Blueprint $table) {
            // Drop existing columns
            $table->dropColumn(['walkable_areas', 'navmeta']);
        });

        Schema::connection('sakemaru')->table('wms_warehouse_layouts', function (Blueprint $table) {
            // Add columns after picking_end_y
            $table->json('walkable_areas')->nullable()->after('picking_end_y')->comment('歩行可能領域（ポリゴン配列。穴あり。単位はpx）');
            $table->json('navmeta')->nullable()->after('walkable_areas')->comment('ナビ生成用メタ（cell_size, origin等）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_warehouse_layouts', function (Blueprint $table) {
            $table->dropColumn(['walkable_areas', 'navmeta']);
        });

        Schema::connection('sakemaru')->table('wms_warehouse_layouts', function (Blueprint $table) {
            // Add back at the end
            $table->json('walkable_areas')->nullable()->comment('歩行可能領域（ポリゴン配列。穴あり。単位はpx）');
            $table->json('navmeta')->nullable()->comment('ナビ生成用メタ（cell_size, origin等）');
        });
    }
};
