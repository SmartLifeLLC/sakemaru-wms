<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('picking_assignment_strategies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->comment('倉庫ID（FK制約なし）');
            $table->string('name')->comment('管理用表示名');
            $table->text('description')->nullable()->comment('ロジックの説明');
            $table->string('strategy_key')->comment('ロジック識別子（Enum値）');
            $table->json('parameters')->nullable()->comment('ロジック用パラメータ設定 (JSON)');
            $table->boolean('is_default')->default(false)->comment('その倉庫内でのデフォルト設定か');
            $table->boolean('is_active')->default(true)->comment('有効/無効フラグ');
            $table->unsignedBigInteger('creator_id')->default(0)->comment('作成者ID');
            $table->unsignedBigInteger('last_updater_id')->default(0)->comment('最終更新者ID');
            $table->timestamps();

            $table->index('warehouse_id');
            $table->index(['warehouse_id', 'is_default']);
            $table->index(['warehouse_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_picking_assignment_strategies');
    }
};
