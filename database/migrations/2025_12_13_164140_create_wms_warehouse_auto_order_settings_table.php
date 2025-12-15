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
        Schema::connection($this->connection)->create('wms_warehouse_auto_order_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id')->unique()->comment('倉庫ID');
            $table->enum('warehouse_type', ['HUB', 'SATELLITE'])->default('SATELLITE')->comment('倉庫タイプ');
            $table->unsignedBigInteger('hub_warehouse_id')->nullable()->comment('拠点倉庫ID (Satellite倉庫の場合)');
            $table->boolean('is_auto_order_enabled')->default(true)->comment('自動発注有効フラグ');
            $table->boolean('exclude_sunday_arrival')->default(true)->comment('日曜入荷除外フラグ');
            $table->boolean('exclude_holiday_arrival')->default(true)->comment('祝日入荷除外フラグ');
            $table->timestamps();

            $table->index('warehouse_type', 'idx_warehouse_type');
            $table->index('hub_warehouse_id', 'idx_hub_warehouse');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_warehouse_auto_order_settings');
    }
};
