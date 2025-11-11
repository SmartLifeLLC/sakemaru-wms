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
        Schema::connection('sakemaru')->create('wms_warehouse_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('floor_id')->nullable()->comment('フロアID（NULL=倉庫全体のデフォルト設定）');

            // Canvas size settings
            $table->integer('canvas_width')->default(2000)->comment('キャンバス幅（px）');
            $table->integer('canvas_height')->default(1500)->comment('キャンバス高さ（px）');

            // Zone (location) style settings
            $table->string('zone_bg_color', 20)->default('#E0F2FE')->comment('区画背景色');
            $table->string('zone_border_color', 20)->default('#D1D5DB')->comment('区画境界線色');
            $table->string('zone_text_color', 20)->default('#6B7280')->comment('区画文字色');
            $table->integer('zone_text_size')->default(12)->comment('区画文字サイズ（px）');
            $table->string('zone_resize_handle_color', 20)->default('#3B82F6')->comment('区画リサイズハンドル色');
            $table->integer('zone_resize_handle_size')->default(4)->comment('区画リサイズハンドルサイズ（px）');

            // Pillar style settings
            $table->string('pillar_bg_color', 20)->default('#9CA3AF')->comment('柱背景色');
            $table->string('pillar_border_color', 20)->default('#6B7280')->comment('柱境界線色');
            $table->string('pillar_text_color', 20)->default('#FFFFFF')->comment('柱文字色');
            $table->integer('pillar_text_size')->default(10)->comment('柱文字サイズ（px）');
            $table->string('pillar_resize_handle_color', 20)->default('#6B7280')->comment('柱リサイズハンドル色');
            $table->integer('pillar_resize_handle_size')->default(4)->comment('柱リサイズハンドルサイズ（px）');

            // Fixed area style settings (elevator, loading dock, etc.)
            $table->string('fixed_area_bg_color', 20)->default('#FEF3C7')->comment('固定領域背景色');
            $table->string('fixed_area_border_color', 20)->default('#F59E0B')->comment('固定領域境界線色');
            $table->string('fixed_area_text_color', 20)->default('#92400E')->comment('固定領域文字色');
            $table->integer('fixed_area_text_size')->default(12)->comment('固定領域文字サイズ（px）');
            $table->string('fixed_area_resize_handle_color', 20)->default('#F59E0B')->comment('固定領域リサイズハンドル色');
            $table->integer('fixed_area_resize_handle_size')->default(4)->comment('固定領域リサイズハンドルサイズ（px）');

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
