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
            $table->json('walkable_areas')->nullable()->comment('歩行可能領域（ポリゴン配列。穴あり。単位はpx）');
            $table->json('navmeta')->nullable()->comment('ナビ生成用メタ（cell_size, origin等）');
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
    }
};
