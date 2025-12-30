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
        Schema::connection('sakemaru')->create('wms_route_calculation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('picking_task_id')->nullable()->comment('ピッキングタスクID');
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID');
            $table->unsignedBigInteger('floor_id')->comment('フロアID');
            $table->string('algorithm', 50)->default('astar')->comment('使用アルゴリズム (astar, greedy, etc.)');
            $table->integer('cell_size')->default(10)->comment('A*グリッドセルサイズ (px)');
            $table->integer('front_point_delta')->default(5)->comment('Front point delta (px)');
            $table->integer('location_count')->comment('訪問ロケーション数');
            $table->integer('total_distance')->comment('総移動距離 (px)');
            $table->integer('calculation_time_ms')->comment('計算時間 (ms)');
            $table->json('location_order')->comment('訪問順序 (location_ids)');
            $table->json('metadata')->nullable()->comment('その他のメタデータ');
            $table->timestamps();

            $table->index('picking_task_id');
            $table->index('warehouse_id');
            $table->index('floor_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_route_calculation_logs');
    }
};
