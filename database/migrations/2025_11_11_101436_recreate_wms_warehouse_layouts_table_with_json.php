<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop old table
        Schema::connection('sakemaru')->dropIfExists('wms_warehouse_layouts');

        // Create new table with JSON structure
        Schema::connection('sakemaru')->create('wms_warehouse_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('floor_id')->nullable()->comment('フロアID（NULL=倉庫全体のデフォルト設定）');

            // Canvas size
            $table->integer('width')->default(2000)->comment('キャンバス幅（px）');
            $table->integer('height')->default(1500)->comment('キャンバス高さ（px）');

            // Colors - JSON structure
            // {
            //   "location": {"border": "#D1D5DB", "rectangle": "#E0F2FE"},
            //   "wall": {"border": "#6B7280", "rectangle": "#9CA3AF"},
            //   "fixed_area": {"border": "#F59E0B", "rectangle": "#FEF3C7"}
            // }
            $table->json('colors')->nullable()->comment('色設定（JSON）');

            // Text styles - JSON structure
            // {
            //   "location": {"color": "#6B7280", "size": 12},
            //   "wall": {"color": "#FFFFFF", "size": 10},
            //   "fixed_area": {"color": "#92400E", "size": 12}
            // }
            $table->json('text_styles')->nullable()->comment('文字スタイル設定（JSON）');

            // Walls - JSON array
            // [
            //   {"id": 1, "name": "柱1", "x1": 100, "y1": 100, "x2": 150, "y2": 150},
            //   {"id": 2, "name": "柱2", "x1": 200, "y1": 200, "x2": 250, "y2": 250}
            // ]
            $table->json('walls')->nullable()->comment('壁・柱の配置（JSON）');

            // Fixed areas - JSON array
            // [
            //   {"id": 1, "name": "エレベーター", "x1": 300, "y1": 300, "x2": 400, "y2": 400},
            //   {"id": 2, "name": "荷下ろし場", "x1": 500, "y1": 500, "x2": 600, "y2": 600}
            // ]
            $table->json('fixed_areas')->nullable()->comment('固定領域の配置（JSON）');

            $table->timestamps();

            $table->unique(['warehouse_id', 'floor_id'], 'wms_warehouse_layouts_unique');
            $table->index('warehouse_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_warehouse_layouts');
    }
};
